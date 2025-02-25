<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\TwentyI;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;
use TwentyI\API\Authentication;
use TwentyI\API\HTTPException;
use TwentyI\API\Services;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionProviders\SharedHosting\Category as SharedHosting;
use Upmind\ProvisionBase\Helper;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionBase\Result\ProviderResult;
use Upmind\ProvisionProviders\SharedHosting\Data\AccountInfo;
use Upmind\ProvisionProviders\SharedHosting\Data\AccountUsage;
use Upmind\ProvisionProviders\SharedHosting\Data\AccountUsername;
use Upmind\ProvisionProviders\SharedHosting\Data\ChangePackageParams;
use Upmind\ProvisionProviders\SharedHosting\Data\ChangePasswordParams;
use Upmind\ProvisionProviders\SharedHosting\Data\CreateParams;
use Upmind\ProvisionProviders\SharedHosting\Data\EmptyResult;
use Upmind\ProvisionProviders\SharedHosting\Data\GetLoginUrlParams;
use Upmind\ProvisionProviders\SharedHosting\Data\GrantResellerParams;
use Upmind\ProvisionProviders\SharedHosting\Data\LoginUrl;
use Upmind\ProvisionProviders\SharedHosting\Data\ResellerOptionParams;
use Upmind\ProvisionProviders\SharedHosting\Data\ResellerPrivileges;
use Upmind\ProvisionProviders\SharedHosting\Data\SuspendParams;
use Upmind\ProvisionProviders\SharedHosting\Data\UnitsConsumed;
use Upmind\ProvisionProviders\SharedHosting\Data\UsageData;
use Upmind\ProvisionProviders\SharedHosting\TwentyI\Data\TwentyICredentials;

/**
 * Provision 20i shared hosting packages via their reseller API.
 *
 * @link https://my.20i.com/reseller/apiDoc
 */
class Provider extends SharedHosting implements ProviderInterface
{
    /**
     * @var Api|null
     */
    protected $api;

    /**
     * @var TwentyICredentials
     */
    protected $configuration;

    public function __construct(TwentyICredentials $configuration)
    {
        $this->configuration = $configuration;
    }

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('20i Hosting')
            ->setDescription('Create and manage 20i hosting accounts via the reseller API')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/20i-logo@2x.png');
    }

    public function create(CreateParams $params): AccountInfo
    {
        if (!$params->domain) {
            throw $this->errorResult('Domain name is required');
        }

        $hostingId = $this->api()->createPackage(
            $params->package_name,
            $params->domain,
            $customerId = $this->findOrCreateUser($params)
        );

        return $this->getAccountInfoData($hostingId)
            ->setCustomerId($customerId);
    }

    public function getInfo(AccountUsername $params): AccountInfo
    {
        $info = $this->getAccountInfoData($params->username);

        if ($params->customer_id) {
            $info->setCustomerId($params->customer_id);
        }

        return $info;
    }

    public function getUsage(AccountUsername $params): AccountUsage
    {
        $usage = $this->api()->getPackageUsage($params->username);
        $info = $this->api()->getPackageInfo($params->username);
        $limits = $info->limits;

        $websites = UnitsConsumed::create()
            ->setUsed(count($info->names))
            ->setLimit($limits->domains !== 'INF' ? $limits->domains : null);

        $disk = UnitsConsumed::create()
            ->setUsed($usage->DiskMb)
            ->setLimit($limits->webspace !== 'INF' ? $limits->webspace : null);

        $bandwidth = UnitsConsumed::create()
            ->setUsed($usage->Bandwidth->MbIn + $usage->Bandwidth->MbOut)
            ->setLimit($limits->bandwidth !== 'INF' ? $limits->bandwidth : null);

        $usage = UsageData::create()
            ->setWebsites($websites)
            ->setDiskMb($disk)
            ->setBandwidthMb($bandwidth);

        return AccountUsage::create()
            ->setUsageData($usage);
    }

    public function getLoginUrl(GetLoginUrlParams $params): LoginUrl
    {
        $hostingId = $params->username;

        $info = $this->api()->getPackageInfo($hostingId);

        $stackUser = Arr::first($info->stackUsers, function ($stackUser) use ($params) {
            return $params->customer_id && Str::start($params->customer_id, 'stack-user:') === $stackUser;
        }, Arr::first($info->stackUsers));

        if (!$stackUser) {
            return $this->errorResult('Hosting package has no stack user assigned');
        }

        [$loginUrl, $ttl] = $this->api()->getLoginUrl($stackUser, $info->web->name);

        return LoginUrl::create()
            ->setLoginUrl($loginUrl)
            ->setExpires(Carbon::now()->addSeconds($ttl))
            ->setForIp(null);
    }

    public function grantReseller(GrantResellerParams $params): ResellerPrivileges
    {
        throw $this->errorResult('Function not available for 20i accounts');
    }

    public function revokeReseller(AccountUsername $params): ResellerPrivileges
    {
        throw $this->errorResult('Function not available for 20i accounts');
    }

    public function changePassword(ChangePasswordParams $params): EmptyResult
    {
        $hostingId = $params->username;

        $info = $this->api()->getPackageInfo($hostingId);

        $stackUser = Arr::first($info->stackUsers, function ($stackUser) use ($params) {
            return $params->customer_id && Str::start($params->customer_id, 'stack-user:') === $stackUser;
        }, Arr::first($info->stackUsers));

        if (!$stackUser) {
            return $this->errorResult('Hosting package has no stack user assigned');
        }

        $this->api()->changeStackUserPassword($stackUser, $params->password);

        return EmptyResult::create()->setMessage('Password changed');
    }

    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        $hostingId = $params->username;
        $planId = $params->package_name;

        $hostingInfo = $this->api()->getPackageInfo($hostingId)->web; // throws error if hosting is deleted
        $planInfo = $this->api()->getPlanInfo($planId); // throws error if plan not found

        if ($hostingInfo->platform !== $planInfo->platform) {
            // check platforms match - 20i do not support change of platform
            $errorMessage = sprintf(
                'Cannot change platform from %s to %s',
                $hostingInfo->platform,
                $planInfo->platform
            );
            return $this->errorResult($errorMessage, [
                'hosting_id' => $hostingId,
                'new_plan_id' => $planId,
            ]);
        }

        $this->api()->changePackagePlan($hostingId, $planId);

        return $this->getAccountInfoData($params->username);
    }

    public function suspend(SuspendParams $params): AccountInfo
    {
        $hostingId = $params->username;

        $info = $this->getAccountInfoData($hostingId) // throws error if hosting is deleted
            ->setSuspendReason($params->reason);

        if ($info->suspended) {
            return $info->setMessage('Hosting package already suspended');
        }

        $this->api()->disablePackage($hostingId);

        return $info->setMessage('Hosting package suspended')
            ->setSuspended(true);
    }

    public function unSuspend(AccountUsername $params): AccountInfo
    {
        $hostingId = $params->username;

        $info = $this->getAccountInfoData($hostingId) // throws error if hosting is deleted
            ->setSuspendReason(null);

        if (!$info->suspended) {
            return $info->setMessage('Hosting package already unsuspended');
        }

        $this->api()->enablePackage($hostingId);

        return $info->setMessage('Hosting package unsuspended')
            ->setSuspended(false);
    }

    public function terminate(AccountUsername $params): EmptyResult
    {
        $hostingId = $params->username;

        $this->getAccountInfoData($hostingId); // throws error if hosting already deleted

        $this->api()->terminatePackage($hostingId);

        return EmptyResult::create()
            ->setMessage('Hosting package deleted');
    }

    protected function getAccountInfoData($hostingId): AccountInfo
    {
        $info = $this->api()->getPackageInfo($hostingId);

        $domain = $info->web->name;
        $ip = $info->web->info->ip4Address;
        $serverHost = $ip;
        $packageName = $info->web->typeRef;
        $suspended = !$info->status;
        $suspendReason = null;
        $reseller = false;

        return AccountInfo::create()
            ->setUsername((string)$hostingId)
            ->setDomain($domain)
            ->setReseller($reseller)
            ->setServerHostname($serverHost)
            ->setPackageName((string)$packageName)
            ->setSuspended($suspended)
            ->setSuspendReason($suspendReason)
            ->setIp($ip);
    }

    /**
     * Combine hosting id and stack user reference to return a single username
     * string.
     *
     * @param int $hostingId Hosting package id E.g., 1234
     * @param string $stackUser Stack user reference E.g, stack-user:4567
     *
     * @return string Combined username string E.g., hosting:1234|stack-user:4567
     */
    protected function getCombinedUsername(?int $hostingId, ?string $stackUser): string
    {
        $parts = [];

        if ($hostingId) {
            $parts[] = sprintf('hosting:%s', $hostingId);
        }

        if ($stackUser) {
            $parts[] = is_numeric($stackUser)
                ? sprintf('stack-user:%s', $stackUser)
                : $stackUser;
        }

        return implode('|', $parts);
    }

    /**
     * Parse hosting id and stack user reference from the username string (which
     * may contain just hosting id, or a combination of hosting id and stack user
     * reference).
     *
     * @param string $username E.g., 'hosting:1235|stack-user:5678'
     *
     * @return array E.g., [1235, 'stack-user:5678']
     */
    protected function getHostingIdAndStackUser($username): array
    {
        if (is_numeric($username)) {
            $hostingId = $username;
        } else {
            $parts = explode('|', (string)$username);

            foreach ($parts as $part) {
                if (Str::startsWith($part, 'hosting:')) {
                    $hostingId = (int)Str::after($part, 'hosting:');
                }

                if (Str::startsWith($part, 'stack-user:')) {
                    $stackUser = $part;
                }
            }
        }

        if (empty($hostingId)) {
            return $this->errorResult('Unable to determine hosting id', ['username' => $username]);
        }

        return [$hostingId, $stackUser ?? null];
    }

    /**
     * Find an existing stack user by email (if auto-detect enabled), or create
     * a new one.
     *
     * @return string Stack user reference
     */
    protected function findOrCreateUser(CreateParams $params): string
    {
        if ($params->customer_id) {
            return Str::start($params->customer_id, 'stack-user:');
        }

        // re-use customer email address for stack user

        $existingUser = $this->api()->searchForStackUser($params->email);

        if ($existingUser) {
            return $existingUser;
        }

        return $this->api()->createStackUser($params->email);
    }

    /**
     * Create a new stack user by combining email and domain name.
     *
     * @return string Stack user reference
     */
    protected function createUniqueUser(CreateParams $params): string
    {
        // create a new unique user for this domain

        $email = $this->getEmailPlusDomain($params->email, $params->domain);

        return $this->api()->createStackUser($email);
    }

    /**
     * Returns the given email address with the given domain name in the sub-folder
     * section.
     *
     * @param string $email E.g., harry@upmind.com
     * @param string $domain E.g., harrydev.uk
     *
     * @return string E.g., harry+harrydev-uk@upmind.com
     */
    protected function getEmailPlusDomain(string $email, string $domain): string
    {
        $emailParts = explode('@', $email, 2);
        $domain = preg_replace('/[^a-z0-9]/i', '-', $domain);

        return Str::contains($emailParts[0], '+')
            ? sprintf('%s-%s@%s', $emailParts[0], Str::slug($domain), $emailParts[1])
            : sprintf('%s+%s@%s', $emailParts[0], Str::slug($domain), $emailParts[1]);
    }

    protected function api(): Api
    {
        return $this->api = ($this->api = new Api(
            $this->configuration->general_api_key,
            $this->configuration->debug ? $this->getLogger() : null
        ));
    }
}
