<?php

declare(strict_types=1);

namespace justinholtweb\subscribr;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\events\CartPurgeEvent;
use craft\commerce\services\Carts as CommerceCarts;
use craft\commerce\services\OrderAdjustments;
use craft\db\Query;
use craft\elements\User;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\subscribr\adjusters\SubscriptionAdjuster;
use justinholtweb\subscribr\db\Table;
use justinholtweb\subscribr\elements\Subscription;
use justinholtweb\subscribr\models\Settings;
use justinholtweb\subscribr\services\Billing;
use justinholtweb\subscribr\services\Boxes;
use justinholtweb\subscribr\services\Carts;
use justinholtweb\subscribr\services\Dunning;
use justinholtweb\subscribr\services\Gifts;
use justinholtweb\subscribr\services\Ledger;
use justinholtweb\subscribr\services\Notifications;
use justinholtweb\subscribr\services\Plans;
use justinholtweb\subscribr\services\Proration;
use justinholtweb\subscribr\services\Renewals;
use justinholtweb\subscribr\services\Schedules;
use justinholtweb\subscribr\services\Subscriptions;
use justinholtweb\subscribr\twig\SubscribrVariable;
use justinholtweb\subscribr\widgets\SubscriptionsWidget;
use yii\base\Event;

/**
 * Subscribr — recurring commerce for Craft.
 *
 * @property-read Plans $plans
 * @property-read Subscriptions $subscriptions
 * @property-read Renewals $renewals
 * @property-read Billing $billing
 * @property-read Dunning $dunning
 * @property-read Boxes $boxes
 * @property-read Schedules $schedules
 * @property-read Proration $proration
 * @property-read Gifts $gifts
 * @property-read Carts $carts
 * @property-read Ledger $ledger
 * @property-read Notifications $notifications
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public const HANDLE = 'subscribr';

    public const EDITION_LITE = 'lite';
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '5.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function editions(): array
    {
        return [self::EDITION_LITE, self::EDITION_PRO];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'plans' => ['class' => Plans::class],
                'subscriptions' => ['class' => Subscriptions::class],
                'renewals' => ['class' => Renewals::class],
                'billing' => ['class' => Billing::class],
                'dunning' => ['class' => Dunning::class],
                'boxes' => ['class' => Boxes::class],
                'schedules' => ['class' => Schedules::class],
                'proration' => ['class' => Proration::class],
                'gifts' => ['class' => Gifts::class],
                'carts' => ['class' => Carts::class],
                'ledger' => ['class' => Ledger::class],
                'notifications' => ['class' => Notifications::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->_registerSiteTemplates();
        $this->_registerTwigVariable();
        $this->_registerPermissions();
        $this->_registerRoutes();
        $this->_registerElementTypes();
        $this->_registerWidgets();

        // Subscribr can be installed while Commerce is disabled or mid-upgrade, and everything
        // below reaches for classes that would not be there.
        if (!self::commerceIsReady()) {
            return;
        }

        $this->_registerAdjuster();
        $this->_registerOrderEvents();
        $this->_protectRenewalCarts();
        $this->_registerUserPanel();
    }

    public static function commerceIsReady(): bool
    {
        return class_exists(\craft\commerce\Plugin::class)
            && Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    /**
     * Whether this install is licensed for the Pro feature set: subscription boxes, skip and swap,
     * prepaid and gift plans, plan switching with proration, and dunning profiles.
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    // Edition gates
    // -------------------------------------------------------------------------
    //
    // Each of these answers "is this feature on for this install", not "is this Pro". A Lite
    // install that once had Pro must not obey a Pro configuration it can no longer edit — a box
    // plan whose slots are unreachable would ship whatever it liked — so the gates read the
    // edition rather than the stored setting.

    public function getEffectiveBoxesEnabled(): bool
    {
        return $this->isPro();
    }

    public function getEffectiveSkipEnabled(): bool
    {
        return $this->isPro();
    }

    public function getEffectiveSwapEnabled(): bool
    {
        return $this->isPro();
    }

    public function getEffectivePrepaidEnabled(): bool
    {
        return $this->isPro();
    }

    public function getEffectiveGiftsEnabled(): bool
    {
        return $this->isPro();
    }

    public function getEffectiveSwitchingEnabled(): bool
    {
        return $this->isPro();
    }

    /**
     * Dunning itself is in Lite — a free-tier store whose cards decline still needs its money.
     * What Pro adds is *named profiles*: different sequences for different plans. Lite runs the
     * one sequence in the plugin settings.
     */
    public function getEffectiveDunningProfilesEnabled(): bool
    {
        return $this->isPro();
    }

    /**
     * Whether any Pro setting is currently being suppressed.
     *
     * Surfaced in the CP so a downgraded install is told why its box plans stopped offering boxes,
     * rather than discovering it in a support ticket.
     */
    public function getHasSuppressedProSettings(): bool
    {
        if ($this->isPro()) {
            return false;
        }

        return (new Query())->from([Table::BOXES])->exists()
            || (new Query())->from([Table::DUNNING])->exists()
            || (new Query())->from([Table::PLANS])->where(['not', ['boxId' => null]])->exists();
    }

    // Services
    // -------------------------------------------------------------------------

    public function getPlans(): Plans
    {
        return $this->get('plans');
    }

    public function getSubscriptions(): Subscriptions
    {
        return $this->get('subscriptions');
    }

    public function getRenewals(): Renewals
    {
        return $this->get('renewals');
    }

    public function getBilling(): Billing
    {
        return $this->get('billing');
    }

    public function getDunning(): Dunning
    {
        return $this->get('dunning');
    }

    public function getBoxes(): Boxes
    {
        return $this->get('boxes');
    }

    public function getSchedules(): Schedules
    {
        return $this->get('schedules');
    }

    public function getProration(): Proration
    {
        return $this->get('proration');
    }

    public function getGifts(): Gifts
    {
        return $this->get('gifts');
    }

    public function getCarts(): Carts
    {
        return $this->get('carts');
    }

    public function getLedger(): Ledger
    {
        return $this->get('ledger');
    }

    public function getNotifications(): Notifications
    {
        return $this->get('notifications');
    }

    // CP
    // -------------------------------------------------------------------------

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $user = Craft::$app->getUser()->getIdentity();

        $item['label'] = Craft::t('subscribr', 'Subscribr');
        $item['subnav']['subscriptions'] = [
            'label' => Craft::t('subscribr', 'Subscriptions'),
            'url' => 'subscribr/subscriptions',
        ];

        if ($user?->can('subscribr-manageDunning')) {
            $item['subnav']['dunning'] = [
                'label' => Craft::t('subscribr', 'Dunning'),
                'url' => 'subscribr/dunning',
            ];
        }

        if ($user?->can('subscribr-managePlans')) {
            $item['subnav']['plans'] = [
                'label' => Craft::t('subscribr', 'Plans'),
                'url' => 'subscribr/plans',
            ];

            if ($this->getEffectiveBoxesEnabled()) {
                $item['subnav']['boxes'] = [
                    'label' => Craft::t('subscribr', 'Boxes'),
                    'url' => 'subscribr/boxes',
                ];
            }
        }

        if ($user?->can('accessPlugin-subscribr')) {
            $item['subnav']['gateways'] = [
                'label' => Craft::t('subscribr', 'Gateways'),
                'url' => 'subscribr/gateways',
            ];
        }

        return $item;
    }

    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(\craft\helpers\UrlHelper::cpUrl('subscribr/settings'));
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    // Wiring
    // -------------------------------------------------------------------------

    /**
     * Make Subscribr's templates addressable from the front end.
     *
     * A plugin's templates are CP-only by default, and the portal is not a CP thing. Registering
     * the root also gives a site a clean override path: a `templates/subscribr/portal/…` of its
     * own wins over the plugin's.
     */
    private function _registerSiteTemplates(): void
    {
        Event::on(View::class, View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS, static function(RegisterTemplateRootsEvent $event): void {
            $event->roots['subscribr'] = __DIR__ . '/templates';
        });
    }

    private function _registerTwigVariable(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, static function(Event $event): void {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('subscribr', SubscribrVariable::class);
        });
    }

    private function _registerElementTypes(): void
    {
        Event::on(Elements::class, Elements::EVENT_REGISTER_ELEMENT_TYPES, static function(RegisterComponentTypesEvent $event): void {
            $event->types[] = Subscription::class;
        });
    }

    private function _registerWidgets(): void
    {
        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, static function(RegisterComponentTypesEvent $event): void {
            $event->types[] = SubscriptionsWidget::class;
        });
    }

    private function _registerAdjuster(): void
    {
        Event::on(OrderAdjustments::class, OrderAdjustments::EVENT_REGISTER_ORDER_ADJUSTERS, static function(RegisterComponentTypesEvent $event): void {
            $event->types[] = SubscriptionAdjuster::class;
        });
    }

    private function _registerOrderEvents(): void
    {
        // Order completion does two jobs: turn a signup into subscriptions, and recover a manual
        // renewal the customer has just paid. Both are idempotent, because this event can fire
        // more than once for one order and neither is safe to do twice.
        Event::on(Order::class, Order::EVENT_AFTER_COMPLETE_ORDER, static function(Event $event): void {
            /** @var Order $order */
            $order = $event->sender;
            $plugin = self::getInstance();

            if ($plugin === null) {
                return;
            }

            $plugin->getRenewals()->completeManualRenewal($order);
            $plugin->getCarts()->materialize($order);
        });
    }

    /**
     * Keep Commerce's abandoned-cart purge away from unpaid renewals.
     *
     * A manual renewal is deliberately left as an *incomplete* order so that Commerce's own
     * load-cart URL drops the subscriber into the store's real checkout. But Commerce purges
     * incomplete orders that have not been touched for `purgeInactiveCartsDuration` — 90 days by
     * default — and a purge that deletes an unpaid invoice takes the record of the debt with it.
     * Subscribr's orders are excluded by ID.
     */
    private function _protectRenewalCarts(): void
    {
        Event::on(CommerceCarts::class, CommerceCarts::EVENT_BEFORE_PURGE_INACTIVE_CARTS, static function(CartPurgeEvent $event): void {
            $event->inactiveCartsQuery->andWhere([
                'not in',
                'orders.id',
                (new Query())->select(['orderId'])->from([Table::ORDERS]),
            ]);
        });
    }

    /**
     * A subscriptions panel on the user's own edit screen — where support actually looks when a
     * customer is on the phone.
     */
    private function _registerUserPanel(): void
    {
        Event::on(User::class, User::EVENT_DEFINE_SIDEBAR_HTML, static function(DefineHtmlEvent $event): void {
            /** @var User $user */
            $user = $event->sender;
            $plugin = self::getInstance();

            if ($plugin === null || !$user->id || !Craft::$app->getUser()->checkPermission('subscribr-viewSubscriptions')) {
                return;
            }

            $subscriptions = $plugin->getSubscriptions()->getSubscriptionsForUser((int)$user->id, true);

            if ($subscriptions === []) {
                return;
            }

            $event->html .= Craft::$app->getView()->renderTemplate('subscribr/_includes/_userpanel', [
                'subscriptions' => $subscriptions,
            ]);
        });
    }

    private function _registerPermissions(): void
    {
        Event::on(UserPermissions::class, UserPermissions::EVENT_REGISTER_PERMISSIONS, static function(RegisterUserPermissionsEvent $event): void {
            $event->permissions[] = [
                'heading' => Craft::t('subscribr', 'Subscribr'),
                'permissions' => [
                    'subscribr-viewSubscriptions' => [
                        'label' => Craft::t('subscribr', 'View subscriptions'),
                        'nested' => [
                            'subscribr-manageSubscriptions' => [
                                'label' => Craft::t('subscribr', 'Change subscriptions'),
                            ],
                            'subscribr-billSubscriptions' => [
                                'label' => Craft::t('subscribr', 'Take payments and issue renewals'),
                            ],
                        ],
                    ],
                    'subscribr-managePlans' => [
                        'label' => Craft::t('subscribr', 'Manage plans and boxes'),
                    ],
                    'subscribr-manageDunning' => [
                        'label' => Craft::t('subscribr', 'Manage dunning'),
                    ],
                ],
            ];
        });
    }

    private function _registerRoutes(): void
    {
        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, static function(RegisterUrlRulesEvent $event): void {
            $event->rules = array_merge($event->rules, [
                'subscribr' => 'subscribr/subscriptions/index',
                'subscribr/subscriptions' => 'subscribr/subscriptions/index',
                'subscribr/subscriptions/<subscriptionId:\d+>' => 'subscribr/subscriptions/edit',
                'subscribr/plans' => 'subscribr/plans/index',
                'subscribr/plans/new' => 'subscribr/plans/edit',
                'subscribr/plans/<planId:\d+>' => 'subscribr/plans/edit',
                'subscribr/boxes' => 'subscribr/boxes/index',
                'subscribr/boxes/new' => 'subscribr/boxes/edit',
                'subscribr/boxes/<boxId:\d+>' => 'subscribr/boxes/edit',
                'subscribr/dunning' => 'subscribr/dunning/index',
                'subscribr/dunning/profiles/new' => 'subscribr/dunning/edit',
                'subscribr/dunning/<profileId:\d+>' => 'subscribr/dunning/edit',
                'subscribr/gateways' => 'subscribr/gateways/index',
                'subscribr/settings' => 'subscribr/settings/index',
            ]);
        });

        Event::on(UrlManager::class, UrlManager::EVENT_REGISTER_SITE_URL_RULES, static function(RegisterUrlRulesEvent $event): void {
            $settings = self::getInstance()?->getSettings();

            if ($settings === null || !$settings->enablePortal) {
                return;
            }

            $portal = trim($settings->portalPath, '/');
            $claim = trim($settings->giftClaimPath, '/');

            $event->rules[$portal] = ['template' => 'subscribr/portal/index'];
            $event->rules[$portal . '/<reference:[A-Za-z0-9\-]+>'] = ['template' => 'subscribr/portal/subscription'];
            $event->rules[str_replace('{token}', '<token:[A-Za-z0-9\-]+>', $claim)] = ['template' => 'subscribr/portal/claim'];
        });
    }
}
