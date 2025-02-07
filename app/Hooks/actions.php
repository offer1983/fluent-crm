<?php

/**
 * @var \FluentCrm\Framework\Foundation\Application $app
 */

/*
 * Note: Namespace will be added automatically. For example, if you use MyClass
 * as the controller name then it will become FluentCrm\App\Hooks\Handlers\MyClass.
 */


$app->addAction('fluentcrm_contacts_filter_subscriber', function ($query, $filters) {
    return (new \FluentCrm\App\Models\Subscriber)->buildGeneralPropertiesFilterQuery($query, $filters);
}, 10, 2);

$app->addAction('fluentcrm_contacts_filter_segment', function ($query, $filters) {
    return (new \FluentCrm\App\Models\Subscriber)->buildSegmentFilterQuery($query, $filters);
}, 10, 2);

$app->addAction('fluentcrm_contacts_filter_custom_fields', function ($query, $filters) {
    return (new \FluentCrm\App\Models\Subscriber)->buildCustomFieldsFilterQuery($query, $filters);
}, 10, 2);

$app->addAction('fluentcrm_contacts_filter_activities', function ($query, $filters) {
    return (new \FluentCrm\App\Models\Subscriber)->buildActivitiesFilterQuery($query, $filters);
}, 10, 2);

$app->addAction('fluentcrm_scheduled_minute_tasks', 'Scheduler@process');
$app->addAction('fluentcrm_scheduled_hourly_tasks', 'Scheduler@processHourly');
$app->addAction('fluentcrm_process_contact_jobs', 'Scheduler@processForSubscriber', 999, 1);
$app->addAction('fluentcrm_scheduled_weekly_tasks', 'Scheduler@processWeekly');

// Add admin init

$app->addAction('wp_loaded', 'AdminMenu@init');

$app->addAction('fluentcrm_campaign_status_active', 'CampaignGuard@checkIsActive');

$app->addAction('fluentcrm_campaign_status_working', 'CampaignGuard@checkIsWorking');

$app->addAction('init', 'ExternalPages@route', 99);

$app->addAction('wp_ajax_fluentcrm_unsubscribe_ajax', 'ExternalPages@handleUnsubscribe');
$app->addAction('wp_ajax_nopriv_fluentcrm_unsubscribe_ajax', 'ExternalPages@handleUnsubscribe');

$app->addAction('wp_ajax_fluentcrm_manage_preferences_ajax', 'ExternalPages@handleManageSubPref');
$app->addAction('wp_ajax_nopriv_fluentcrm_manage_preferences_ajax', 'ExternalPages@handleManageSubPref');

$app->addAction('wp_ajax_fluentcrm_callback_for_background', 'ExternalPages@handleBackgroundProcessCallback');
$app->addAction('wp_ajax_nopriv_fluentcrm_callback_for_background', 'ExternalPages@handleBackgroundProcessCallback');

// Fallback for funnel sequence save ajax

$app->addAction('wp_ajax_fluentcrm_save_funnel_sequence_ajax', 'FunnelHandler@saveSequences');
$app->addAction('wp_ajax_fluentcrm_export_funnel', 'FunnelHandler@exportFunnel');


/*
 * Integrations
 */
$app->addAction('init', 'Integrations@register');

/*
 * Funnel
 */
$app->addAction('fluentcrm_addons_loaded', 'FunnelHandler@handle');

$app->addAction('fluentcrm_subscriber_status_to_subscribed', 'FunnelHandler@resumeSubscriberFunnels', 1, 2);

/*
 * Cleanup Hooks
 */
$app->addAction('fluentcrm_after_subscribers_deleted', 'Cleanup@deleteSubscribersAssets', 10, 1);
$app->addAction('fluentcrm_campaign_deleted', 'Cleanup@deleteCampaignAssets', 10, 1);
$app->addAction('fluentcrm_list_deleted', 'Cleanup@deleteListAssets', 10, 1);
$app->addAction('fluentcrm_tag_deleted', 'Cleanup@deleteTagAssets', 10, 1);

$app->addAction('fluentcrm_subscriber_status_to_unsubscribed', 'Cleanup@handleUnsubscribe');
$app->addAction('fluentcrm_subscriber_status_to_bounced', 'Cleanup@handleUnsubscribe');
$app->addAction('fluentcrm_subscriber_status_to_complained', 'Cleanup@handleUnsubscribe');

$app->addAction('fluentcrm_contact_email_changed', 'Cleanup@handleContactEmailChanged');

/*
 * Admin Bar
 */
$app->addAction('admin_bar_menu', 'AdminBar@init');

// This is required to instantly send emails
add_action('wp_ajax_nopriv_fluentcrm-post-campaigns-send-now', function () use ($app) {
    $nextCron = wp_next_scheduled('fluentcrm_scheduled_minute_tasks') - time();
    if ($nextCron > 3 || $nextCron < -60) { // If next cron is after more than 3 seconds we want to run this
        $mailer = new \FluentCrm\App\Services\Libs\Mailer\Handler();
        $mailer->handle(
            $app->request->get('campaign_id')
        );

        wp_send_json_success([
            'message'   => 'success',
            'dip'       => $nextCron,
            'timestamp' => time()
        ]);
    }
});

/*
 * For Short URL Redirect
 */
add_action('wp_loaded', function () use ($app) {
    if (isset($_GET['ns_url'])) {
        (new \FluentCrm\App\Hooks\Handlers\RedirectionHandler())->redirect($_GET);
    } else if (isset($_GET['do_fluentcrm_scheduled_tasks'])) {
        do_action('fluentcrm_scheduled_minute_tasks');
        wp_send_json_success();
    } else if (isset($_GET['fluentcrm_scheduled_hourly_tasks'])) {
        do_action('fluentcrm_scheduled_hourly_tasks');
        wp_send_json_success();
    }
});

/*
 * Contact Activity Logger Class Init
 */
add_action('init', function () {
    (new \FluentCrm\App\Hooks\Handlers\ContactActivityLogger())->register();
});

/*
 * Setup-wizard
 */

if (!empty($_GET['page']) && 'fluentcrm-setup' == $_GET['page']) {
    add_action('admin_menu', function () {
        add_dashboard_page('FluentCRM Setup', 'FluentCRM Setup', 'manage_options', 'fluentcrm-setup', function () {
            return '';
        });
    });

    add_action('current_screen', function () {
        new \FluentCrm\App\Hooks\Handlers\SetupWizard();
    }, 999);
}


$app->addAction('user_register', 'AutoSubscribeHandler@userRegistrationHandler', 99, 1);
$app->addAction('comment_post', 'AutoSubscribeHandler@handleCommentPost', 99, 3);

$app->addAction('profile_update', 'AutoSubscribeHandler@syncUserUpdate', 10, 2);
$app->addAction('delete_user', 'AutoSubscribeHandler@maybeDeleteContact', 10, 3);


// require the CLI
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('fluent_crm', '\FluentCrm\App\Hooks\CLI\Commands');
}
