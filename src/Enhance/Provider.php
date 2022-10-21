<?php

declare(strict_types=1);

namespace Upmind\ProvisionProviders\SharedHosting\Enhance;

use GuzzleHttp\Client;
use Throwable;
use Upmind\EnhanceSdk\ApiException;
use Upmind\EnhanceSdk\Model\DomainIp;
use Upmind\EnhanceSdk\Model\LoginInfo;
use Upmind\EnhanceSdk\Model\Member;
use Upmind\EnhanceSdk\Model\NewCustomer;
use Upmind\EnhanceSdk\Model\NewMember;
use Upmind\EnhanceSdk\Model\NewSubscription;
use Upmind\EnhanceSdk\Model\NewWebsite;
use Upmind\EnhanceSdk\Model\PhpVersion;
use Upmind\EnhanceSdk\Model\Plan;
use Upmind\EnhanceSdk\Model\Role;
use Upmind\EnhanceSdk\Model\ServerIp;
use Upmind\EnhanceSdk\Model\Status;
use Upmind\EnhanceSdk\Model\UpdateSubscription;
use Upmind\EnhanceSdk\Model\UpdateWebsite;
use Upmind\EnhanceSdk\Model\Website;
use Upmind\ProvisionBase\Exception\ProvisionFunctionError;
use Upmind\ProvisionBase\Helper;
use Upmind\ProvisionBase\Provider\Contract\ProviderInterface;
use Upmind\ProvisionBase\Provider\DataSet\AboutData;
use Upmind\ProvisionProviders\SharedHosting\Category;
use Upmind\ProvisionProviders\SharedHosting\Data\CreateParams;
use Upmind\ProvisionProviders\SharedHosting\Data\AccountInfo;
use Upmind\ProvisionProviders\SharedHosting\Data\AccountUsername;
use Upmind\ProvisionProviders\SharedHosting\Data\ChangePackageParams;
use Upmind\ProvisionProviders\SharedHosting\Data\ChangePasswordParams;
use Upmind\ProvisionProviders\SharedHosting\Data\EmptyResult;
use Upmind\ProvisionProviders\SharedHosting\Data\GetLoginUrlParams;
use Upmind\ProvisionProviders\SharedHosting\Data\GrantResellerParams;
use Upmind\ProvisionProviders\SharedHosting\Data\LoginUrl;
use Upmind\ProvisionProviders\SharedHosting\Data\ResellerPrivileges;
use Upmind\ProvisionProviders\SharedHosting\Data\SuspendParams;
use Upmind\ProvisionProviders\SharedHosting\Enhance\Data\Configuration;

class Provider extends Category implements ProviderInterface
{
    /**
     * @var Configuration
     */
    protected $configuration;

    /**
     * @var Api
     */
    protected $api;

    public static function aboutProvider(): AboutData
    {
        return AboutData::create()
            ->setName('Enhance')
            ->setDescription('Create and manage Enhance accounts and resellers using the Enhance API')
            ->setLogoUrl('https://api.upmind.io/images/logos/provision/enhance-logo@2x.png');
    }

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    public function create(CreateParams $params): AccountInfo
    {
        try {
            $plan = $this->findPlan($params->package_name);

            if ($customerId = $params->customer_id) {
                $email = $this->findOwnerMember($customerId, $params->email)->getEmail();
            } else {
                $customerId = $this->createCustomer(
                    $params->customer_name ?? $params->email,
                    $params->email,
                    $params->password ?: $this->generateRandomPassword()
                );
                $email = $params->email;
            }

            $subscriptionId = $this->createSubscription($customerId, $plan->getId());
            $this->createWebsite($customerId, $subscriptionId, $params->domain);

            return $this->getSubscriptionInfo($customerId, $subscriptionId, $params->domain, $email)
                ->setMessage('Website Created');
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function suspend(SuspendParams $params): AccountInfo
    {
        try {
            if (!$params->customer_id || !$params->subscription_id) {
                throw $this->errorResult('Customer ID and Subscription ID are required');
            }

            $updateSubscription = (new UpdateSubscription())
                ->setIsSuspended(true);

            $this->api()->subscriptions()->updateSubscription(
                $params->customer_id,
                $params->subscription_id,
                $updateSubscription
            );

            $info = $this->getSubscriptionInfo(
                $params->customer_id,
                intval($params->subscription_id),
                null,
                $params->username
            );

            return $info->setMessage('Subscription suspended')
                ->setSuspendReason($params->reason);
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function unSuspend(AccountUsername $params): AccountInfo
    {
        try {
            if (!$params->customer_id || !$params->subscription_id) {
                throw $this->errorResult('Customer ID and Subscription ID are required');
            }

            $updateSubscription = (new UpdateSubscription())
                ->setIsSuspended(false);

            $this->api()->subscriptions()->updateSubscription(
                $params->customer_id,
                $params->subscription_id,
                $updateSubscription
            );

            $info = $this->getSubscriptionInfo(
                $params->customer_id,
                intval($params->subscription_id),
                null,
                $params->username
            );

            return $info->setMessage('Subscription unsuspended')
                ->setSuspendReason(null);
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function terminate(AccountUsername $params): EmptyResult
    {
        try {
            if (!$params->customer_id || !$params->subscription_id) {
                throw $this->errorResult('Customer ID and Subscription ID are required');
            }

            $this->api()->subscriptions()
                ->deleteSubscription($params->customer_id, $params->subscription_id, 'false');

            return $this->emptyResult('Subscription deleted');
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function getInfo(AccountUsername $params): AccountInfo
    {
        try {
            if (!$params->customer_id || !$params->subscription_id) {
                throw $this->errorResult('Customer ID and Subscription ID are required');
            }

            return $this->getSubscriptionInfo(
                $params->customer_id,
                intval($params->subscription_id),
                null,
                $params->username
            );
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function getLoginUrl(GetLoginUrlParams $params): LoginUrl
    {
        try {
            $website = $this->findWebsite($params->customer_id, intval($params->subscription_id));

            return LoginUrl::create()
                ->setLoginUrl(sprintf('https://%s/websites/%s', $this->configuration->hostname, $website->getId() ?? null));
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function changePassword(ChangePasswordParams $params): EmptyResult
    {
        try {
            $owner = $this->findOwnerMember($params->customer_id, $params->username);

            $this->api()->logins()->startPasswordRecovery(
                ['email' => $owner->getEmail()],
                $params->customer_id
            );

            return $this->emptyResult('Password reset initiated - please check your email');
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function changePackage(ChangePackageParams $params): AccountInfo
    {
        try {
            if (!$params->customer_id || !$params->subscription_id) {
                throw $this->errorResult('Customer ID and Subscription ID are required');
            }

            $plan = $this->findPlan($params->package_name);

            $updateSubscription = (new UpdateSubscription())
                ->setPlanId($plan->getId());

            $this->api()->subscriptions()->updateSubscription(
                $params->customer_id,
                $params->subscription_id,
                $updateSubscription
            );

            $info = $this->getSubscriptionInfo(
                $params->customer_id,
                intval($params->subscription_id),
                null,
                $params->username
            );

            return $info->setMessage('Subscription plan updated');
        } catch (Throwable $e) {
            throw $this->handleException($e);
        }
    }

    public function grantReseller(GrantResellerParams $params): ResellerPrivileges
    {
        throw $this->errorResult('Operation not supported');
    }

    public function revokeReseller(AccountUsername $params): ResellerPrivileges
    {
        throw $this->errorResult('Operation not supported');
    }

    protected function getSubscriptionInfo(
        string $customerId,
        int $subscriptionId,
        ?string $domain = null,
        ?string $email = null
    ): AccountInfo {
        $subscription = $this->api()->subscriptions()
            ->getSubscription($customerId, $subscriptionId);

        if ($subscription->getStatus() === Status::DELETED) {
            throw $this->errorResult('Subscription terminated', ['subscription' => $subscription->jsonSerialize()]);
        }

        $nameservers = array_map(function (DomainIp $ns) {
            return $ns->getDomain();
        }, $this->api()->branding()->getBranding($this->configuration->org_id)->getNameServers());

        $website = $this->findWebsite($customerId, $subscriptionId, $domain);

        return AccountInfo::create()
            ->setMessage('Subscription info obtained')
            ->setCustomerId($customerId)
            ->setUsername($email ?? $this->findOwnerMember($customerId)->getEmail())
            ->setSubscriptionId($subscriptionId)
            ->setDomain($website ? $website->getDomain()->getDomain() : 'no.websites')
            ->setServerHostname($this->configuration->hostname)
            ->setPackageName($subscription->getPlanName())
            ->setSuspended(boolval($subscription->getSuspendedBy()))
            ->setIp($website ? implode(', ', $this->getWebsiteIps($website)) : null)
            ->setNameservers($nameservers)
            ->setDebug([
                'website' => $website ? $website->jsonSerialize() : null,
                'subscription' => $subscription->jsonSerialize(),
            ]);
    }

    protected function findWebsite(string $customerId, int $subscriptionId, ?string $domain = null): ?Website
    {
        $websites = $this->api()->websites()->getWebsites(
            $customerId,
            null,
            null,
            null,
            null,
            $domain,
            null,
            null,
            $subscriptionId
        );

        if (isset($domain) && $websites->getTotal() !== 1) {
            throw $this->errorResult(sprintf('Found %s websites for the given domain', $websites->getTotal()), [
                'customer_id' => $customerId,
                'subscription_id' => $subscriptionId,
                'domain' => $domain,
            ]);
        }

        return $websites->getItems()[0] ?? null;
    }

    /**
     * @return string[]
     */
    public function getWebsiteIps(Website $website): array
    {
        if ($website->getServerIps()) {
            return array_map(function (ServerIp $ip) {
                return $ip->getIp();
            }, $website->getServerIps());
        }

        $offset = 0;
        $limit = 10;

        while (true) {
            $servers = $this->api()->servers()->getServers($offset, $limit);

            foreach ($servers->getItems() as $server) {
                if ($website->getAppServerId() === $server->getId()) {
                    return array_map(function (ServerIp $ip) {
                        return $ip->getIp();
                    }, $server->getIps());
                }
            }

            if ($servers->getTotal() <= ($offset + $limit)) {
                break;
            }

            $offset += $limit;
        }

        return []; // IPs unknown
    }

    /**
     * Finds the owner member of the given customer id, preferring the given
     * email if it exists.
     */
    protected function findOwnerMember(string $customerId, ?string $email = null): Member
    {
        $firstMember = null;
        $offset = 0;
        $limit = 10;

        while (true) {
            $members = $this->api()->members()->getMembers(
                $customerId,
                $offset,
                $limit,
                null,
                null,
                null,
                Role::OWNER
            );

            foreach ($members->getItems() as $member) {
                if (is_null($email) || $member->getEmail() === $email) {
                    return $member;
                }

                if (is_null($firstMember)) {
                    $firstMember = $member;
                }
            }

            if ($members->getTotal() <= ($offset + $limit)) {
                break;
            }

            $offset += $limit;
        }

        if (is_null($firstMember)) {
            throw $this->errorResult('Customer login not found', [
                'customer_id' => $customerId,
            ]);
        }

        return $firstMember;
    }

    /**
     * Create a new customer org, login and owner membership and return the customer id.
     */
    protected function createCustomer(string $name, string $email, string $password): string
    {
        $newCustomer = (new NewCustomer())
            ->setName($name);
        $customer = $this->api()->customers()
            ->createCustomer($this->configuration->org_id, $newCustomer);

        if (!$customerId = $customer->getId()) {
            throw $this->errorResult('Failed to create new customer', $this->getLastGuzzleRequestDebug() ?? []);
        }

        try {
            $newLogin = (new LoginInfo())
                ->setName($name)
                ->setEmail($email)
                ->setPassword($password);
            $loginId = $this->api()->logins()
                ->createLogin($customerId, $newLogin)
                ->getId();
        } catch (ApiException $e) {
            try {
                $this->api()->orgs()->deleteOrg($customerId, 'false');
            } finally {
                throw $this->handleException(
                    $e,
                    ['new_customer_id' => $customerId, 'email' => $email],
                    [],
                    'Failed to create login for new customer'
                );
            }
        }

        $newMember = (new NewMember())
            ->setLoginId($loginId)
            ->setRoles([
                Role::OWNER,
            ]);
        $this->api()->members()
            ->createMember($customerId, $newMember);

        return $customerId;
    }

    /**
     * Create a new subscription and return the id.
     */
    protected function createSubscription(string $customerId, int $planId): int
    {
        $newSubscription = (new NewSubscription())
            ->setPlanId($planId);

        return $this->api()->subscriptions()
            ->createCustomerSubscription($this->configuration->org_id, $customerId, $newSubscription)
            ->getId();
    }

    /**
     * Create a new website and return the id.
     */
    protected function createWebsite(string $customerId, int $subscriptionId, string $domain): string
    {
        $newWebsite = (new NewWebsite())
            ->setSubscriptionId($subscriptionId)
            ->setDomain($domain);

        $websiteId = $this->api()->websites()
            ->createWebsite($customerId, $newWebsite)
            ->getId();

        $updateWebsite = (new UpdateWebsite())
            ->setPhpVersion(PhpVersion::PHP74);

        $this->api()->websites()->updateWebsite($customerId, $websiteId, $updateWebsite);

        return $websiteId;
    }

    protected function findPlan(string $packageName): Plan
    {
        if (is_numeric($packageName = trim($packageName))) {
            $packageName = intval($packageName);
        }

        $offset = 0;
        $limit = 10;

        while (true) {
            $plans = $this->api()->plans()->getPlans($this->configuration->org_id, $offset, $limit);

            foreach ($plans->getItems() as $plan) {
                if (is_int($packageName) && $packageName === $plan->getId()) {
                    return $plan;
                }

                if (is_string($packageName) && $packageName === trim($plan->getName())) {
                    return $plan;
                }
            }

            if ($plans->getTotal() <= ($offset + $limit)) {
                throw $this->errorResult('Plan not found', [
                    'plan' => $packageName,
                ]);
            }

            $offset += $limit;
        }
    }

    /**
     * Returns a random password 15 chars long containing lower & uppercase alpha,
     * numeric and special characters.
     */
    protected function generateRandomPassword(): string
    {
        return Helper::generateStrictPassword(15, true, true, true);
    }

    /**
     * @param string $string
     */
    protected function isUuid($string): bool
    {
        return boolval(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', (string)$string));
    }

    protected function api(): Api
    {
        if (isset($this->api)) {
            return $this->api;
        }

        $api = new Api($this->configuration);
        $api->setClient(new Client([
            'handler' => $this->getGuzzleHandlerStack(boolval($this->configuration->debug)),
            'headers' => [
                'Authorization' => 'Bearer ' . $this->configuration->access_token,
            ],
        ]));

        return $this->api = $api;
    }

    /**
     * @throws ProvisionFunctionError
     * @throws Throwable
     */
    protected function handleException(Throwable $e, array $data = [], array $debug = [], ?string $message = null): void
    {
        if ($e instanceof ProvisionFunctionError) {
            throw $e->withData(
                array_merge($e->getData(), $data)
            )->withDebug(
                array_merge($e->getDebug(), $debug)
            );
        }

        if ($e instanceof ApiException) {
            $responseData = json_decode($e->getResponseBody(), true);

            $message = $message ?: sprintf('API Request Failed [%s]', $e->getCode());

            $data = array_merge([
                'response_code' => $e->getCode(),
                'response_data' => $responseData,
            ], $data);

            if (is_null($responseData)) {
                $debug['response_body'] = $e->getResponseBody();
            }

            throw $this->errorResult($message, $data, $debug, $e);
        }

        // let the provision system handle this one
        throw $e;
    }
}
