<?php

declare(strict_types=1);

namespace GracjanKubicki\ArchitectureKit\Tests\Unit\Catalog;

use GracjanKubicki\ArchitectureKit\Audit\FileContext;
use GracjanKubicki\ArchitectureKit\Audit\ProjectGraph\ProjectGraphBuilder;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogFacts;
use GracjanKubicki\ArchitectureKit\Catalog\CatalogIndex;
use GracjanKubicki\ArchitectureKit\Context\GraphQuery;
use PHPUnit\Framework\TestCase;

final class StaticGraphPresentationTest extends TestCase
{
    public function test_rendered_view_reaches_registered_public_composer_and_creator_methods(): void
    {
        $index = new CatalogIndex($this->facts([
            'app/Provider.php' => 'use Illuminate\Support\Facades\View as V; V::composer(["billing.*", "other"], App\Composer::class); V::creator("billing.invoice", "App\\Creator@prepare"); V::composer("billing.invoice", App\PrivateComposer::class); throw new \Exception("execution-sentinel");',
            'app/Types.php' => 'namespace App; trait Composes { public function compose($view) { Service::run(); } } class Composer { use Composes; } class Creator { public function prepare($view) { Service::run(); } } class PrivateComposer { private function compose($view) {} } class Service { public static function run() {} } class Controller { public function index() { return view("billing.invoice"); } }',
            'resources/views/billing/invoice.blade.php' => '<h1>Invoice</h1>',
        ]));
        $composers = $this->edges($index, 'view-composer');
        $creators = $this->edges($index, 'view-creator');
        $this->assertCount(1, $composers);
        $this->assertSame('App\Composes::compose', $index->elements[$composers[0]['to']]['name']);
        $this->assertCount(1, $creators);
        $this->assertSame('App\Creator::prepare', $index->elements[$creators[0]['to']]['name']);
        $path = (new GraphQuery($index))->query($index->names[strtolower('App\\Controller::index')][0], 'path', $index->names[strtolower('App\\Service::run')][0], 8);
        $this->assertSame('found', $path['status']);
        $this->assertFalse($path['records'][0]['execution_proven']);
        $this->assertNotEmpty($index->diagnostics);
    }

    public function test_explicit_alias_and_conventional_class_and_anonymous_components_reach_their_views(): void
    {
        $index = new CatalogIndex($this->facts([
            'app/Register.php' => 'use Illuminate\Support\Facades\Blade as B; B::component(App\Card::class, "invoice-card", "billing");',
            'app/Card.php' => 'namespace App; class Card extends \Illuminate\View\Component { public function render() { return view("cards.invoice"); } }',
            'app/View/Components/Notice.php' => 'namespace App\View\Components; class Notice extends \Illuminate\View\Component { public function render() { return view("cards.notice"); } }',
            'resources/views/page.blade.php' => '<x-billing-invoice-card /><x-notice /><x-forms.input /><x-missing />',
            'resources/views/cards/invoice.blade.php' => '<h1>Invoice</h1>',
            'resources/views/cards/notice.blade.php' => '<p>Notice</p>',
            'resources/views/components/forms/input.blade.php' => '<input />',
        ]));
        $this->assertCount(2, $this->edges($index, 'component-render'));
        $this->assertCount(1, $this->edges($index, 'component-view'));
        $query = new GraphQuery($index);
        $page = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'view' && $row['name'] === 'page'))[0];
        foreach (['cards.invoice', 'cards.notice', 'components.forms.input'] as $name) {
            $target = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'view' && $row['name'] === $name))[0];
            $path = $query->query($page['id'], 'path', $target['id'], 8);
            $this->assertSame('found', $path['status']);
            $this->assertFalse($path['records'][0]['execution_proven']);
        }
        $this->assertNotEmpty($index->diagnostics);
    }

    public function test_reused_view_and_registration_facts_resolve_after_component_class_changes(): void
    {
        $fixed = $this->facts([
            'app/Register.php' => '\Illuminate\Support\Facades\Blade::component(App\Card::class, "card");',
            'resources/views/page.blade.php' => '<x-card />',
        ]);
        $fixed = array_map(fn ($row) => CatalogFacts::fromArray($row->path, $row->toArray()), $fixed);
        $public = $this->facts(['app/Card.php' => 'namespace App; class Card extends \Illuminate\View\Component { public function render() { return view("card"); } }'])[0];
        $private = $this->facts(['app/Card.php' => 'namespace App; class Card extends \Illuminate\View\Component { private function render() { return view("card"); } }'])[0];
        $this->assertCount(1, $this->edges(new CatalogIndex([...$fixed, $public]), 'component-render'));
        $this->assertSame([], $this->edges(new CatalogIndex([...$fixed, $private]), 'component-render'));
        $this->assertSame([], $this->edges(new CatalogIndex($fixed), 'component-render'));
    }

    public function test_decoy_component_and_source_facade_shadow_do_not_create_execution_links(): void
    {
        $index = new CatalogIndex($this->facts([
            'app/Blade.php' => 'namespace Illuminate\Support\Facades; class Blade { public static function component($class, $alias) {} }',
            'app/Register.php' => '\Illuminate\Support\Facades\Blade::component(App\Card::class, "card");',
            'app/Card.php' => 'namespace App; class Card extends \Illuminate\View\Component { public function render() {} } class Decoy { public function render() {} }',
            'resources/views/page.blade.php' => '<x-card /><x-decoy />',
        ]));
        $this->assertSame([], $this->edges($index, 'component-render'));
        $this->assertNotEmpty($index->diagnostics);
    }

    public function test_source_view_facade_shadow_keeps_callback_reference_without_execution_transition(): void
    {
        $index = new CatalogIndex($this->facts([
            'app/View.php' => 'namespace Illuminate\\Support\\Facades; class View { public static function composer($views, $callback) {} }',
            'app/Register.php' => '\\Illuminate\\Support\\Facades\\View::composer("page", fn () => App\\Service::run());',
            'app/Service.php' => 'namespace App; class Service { public static function run() {} }',
            'resources/views/page.blade.php' => '<h1>Page</h1>',
        ]));
        $this->assertSame([], $this->edges($index, 'view-composer'));
        $this->assertCount(1, $this->edges($index, 'references-view-callback'));
        $view = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'view' && $row['name'] === 'page'))[0];
        $query = new GraphQuery($index);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($view['id'], 'path', $index->names[strtolower('App\\Service::run')][0], 8)['status']);
        $this->assertNotEmpty($index->diagnostics);
    }

    public function test_mail_delivery_hooks_and_views_are_reached_only_from_a_send_site(): void
    {
        $index = new CatalogIndex($this->facts([
            'app/Mail.php' => <<<'SOURCE'
namespace App;
use Illuminate\Mail\Mailables\Content;
class InvoiceMail extends \Illuminate\Mail\Mailable implements \Illuminate\Contracts\Queue\ShouldQueue {
 public function content() { return new Content(view: 'mail.invoice', with: ['private' => 'payload-secret']); }
 public function unrelated() { return view('mail.unrelated'); }
}
class PlainMail extends \Illuminate\Mail\Mailable { public function build() { return $this->view('mail.plain', ['secret' => 'payload-secret']); } }
class Action {
 public function prepare() { return new InvoiceMail; }
 public function queued() { \Illuminate\Support\Facades\Mail::to('recipient-secret')->send(new InvoiceMail); }
 public function immediate() { \Illuminate\Support\Facades\Mail::sendNow(mailable: new InvoiceMail); }
 public function plain() { \Illuminate\Support\Facades\Mail::send(new PlainMail); }
}
SOURCE,
            'resources/views/mail/invoice.blade.php' => '<p>Invoice</p>',
            'resources/views/mail/plain.blade.php' => '<p>Plain</p>',
        ]));
        $send = $this->edges($index, 'sends-mail');
        $this->assertCount(3, $send);
        $this->assertSame([true, false, false], array_column(array_column($send, 'metadata'), 'queued'));
        $hooks = $this->edges($index, 'mail-delivery-hook');
        $this->assertCount(3, $hooks);
        $this->assertSame('queue-worker-candidate', $hooks[0]['metadata']['execution_stage']);
        $this->assertSame('delivery-preparation', $hooks[1]['metadata']['execution_stage']);
        $this->assertCount(2, $this->edges($index, 'prepares-mail-view'));
        $view = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'view' && $row['name'] === 'mail.invoice'))[0];
        $query = new GraphQuery($index);
        $this->assertSame('found', $query->query($index->names[strtolower('App\\Action::queued')][0], 'path', $view['id'], 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($index->names[strtolower('App\\Action::prepare')][0], 'path', $view['id'], 8)['status']);
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations]));
    }

    public function test_notification_via_is_preparation_even_when_delivery_is_queued(): void
    {
        $index = new CatalogIndex($this->facts(['app/Notifications.php' => 'namespace App; class Notice extends \\Illuminate\\Notifications\\Notification implements \\Illuminate\\Contracts\\Queue\\ShouldQueue { public function via($user) { return ["mail"]; } public function unrelated() {} } class Action { public function send($users) { \\Illuminate\\Support\\Facades\\Notification::send($users, new Notice); \\Illuminate\\Support\\Facades\\Notification::sendNow($users, new Notice); } }']));
        $send = $this->edges($index, 'sends-notification');
        $this->assertSame([true, false], array_column(array_column($send, 'metadata'), 'queued'));
        $via = $this->edges($index, 'notification-channel-selection');
        $this->assertCount(2, $via);
        foreach ($via as $edge) {
            $this->assertSame('App\\Notice::via', $index->elements[$edge['to']]['name']);
            $this->assertSame('delivery-preparation', $edge['metadata']['execution_stage']);
            $this->assertFalse($edge['metadata']['execution_proven']);
        }
    }

    public function test_reused_mail_send_site_refreshes_queue_contract_and_rejects_decoys(): void
    {
        $caller = $this->facts(['app/Action.php' => '\\Illuminate\\Support\\Facades\\Mail::send(new App\\Message);'])[0];
        foreach ([true, false] as $queued) {
            $type = $this->facts(['app/Message.php' => 'namespace App; class Message extends \\Illuminate\\Mail\\Mailable '.($queued ? 'implements \\Illuminate\\Contracts\\Queue\\ShouldQueue' : '').' { public function content() {} }'])[0];
            $index = new CatalogIndex([CatalogFacts::fromArray($caller->path, $caller->toArray()), $type]);
            $this->assertSame($queued, $this->edges($index, 'sends-mail')[0]['metadata']['queued']);
        }
        $decoy = $this->facts(['app/Message.php' => 'namespace App; class Message { public function content() {} }'])[0];
        $index = new CatalogIndex([$caller, $decoy]);
        $this->assertSame([], $this->edges($index, 'sends-mail'));
        $this->assertSame([], $this->edges($index, 'mail-delivery-hook'));
        $this->assertCount(1, $this->edges($index, 'references-mail-candidate'));
        $this->assertNotEmpty($index->diagnostics);
    }

    public function test_trait_build_and_custom_send_override_keep_the_actual_preparation_path(): void
    {
        $index = new CatalogIndex($this->facts(['app/Mail.php' => <<<'SOURCE'
namespace App;
trait Builds { public function build() { return $this->view('mail.shared'); } }
class Shared extends \Illuminate\Mail\Mailable { use Builds; }
class Custom extends \Illuminate\Mail\Mailable { public function send($mailer) { Service::run(); } public function content() { return new \Illuminate\Mail\Mailables\Content(view: 'mail.unreached'); } }
class Service { public static function run() {} }
\Illuminate\Support\Facades\Mail::send(new Shared);
\Illuminate\Support\Facades\Mail::send(new Custom);
SOURCE]));
        $hooks = $this->edges($index, 'mail-delivery-hook');
        $this->assertCount(2, $hooks);
        $this->assertSame(['App\\Builds::build', 'App\\Custom::send'], array_map(fn ($edge) => $index->elements[$edge['to']]['name'], $hooks));
        $views = $this->edges($index, 'prepares-mail-view');
        $this->assertCount(2, $views);
        $this->assertSame('mail.shared', $index->elements[$views[0]['to']]['name']);
        $this->assertNotContains('content', array_column(array_column($hooks, 'metadata'), 'hook'));
    }

    public function test_selected_notification_channels_reach_only_their_bodies_and_custom_send(): void
    {
        $index = new CatalogIndex($this->facts(['app/Notifications.php' => <<<'SOURCE'
namespace App;
class AuditChannel { public function send($recipient, $notification) { Service::audit(); } }
class Service { public static function audit() {} }
class Notice extends \Illuminate\Notifications\Notification implements \Illuminate\Contracts\Queue\ShouldQueue {
 public function via($recipient) { return ['mail', 'database', AuditChannel::class]; }
 public function toMail($recipient) { return (new \Illuminate\Notifications\Messages\MailMessage)->view('notifications.notice', ['secret' => 'payload-secret']); }
 public function toDatabase($recipient) { return ['value' => 'payload-secret']; }
 public function toArray($recipient) { return ['fallback' => 'payload-secret']; }
 public function toBroadcast($recipient) {}
 public function unrelated() {}
}
class Action { public function send($users) { \Illuminate\Support\Facades\Notification::send($users, new Notice); } }
SOURCE,
            'resources/views/notifications/notice.blade.php' => '<p>Notice</p>',
        ]));
        $hooks = $this->edges($index, 'notification-delivery-hook');
        $this->assertCount(2, $hooks);
        $this->assertSame(['toMail', 'toDatabase'], array_column(array_column($hooks, 'metadata'), 'hook'));
        $this->assertCount(1, $this->edges($index, 'notification-custom-channel'));
        foreach ($hooks as $hook) {
            $this->assertSame('queue-worker-candidate', $hook['metadata']['execution_stage']);
            $this->assertFalse($hook['metadata']['execution_proven']);
            $this->assertNotEmpty($hook['metadata']['channel_selection_source']);
        }
        $this->assertCount(1, $this->edges($index, 'prepares-notification-view'));
        $view = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'view' && $row['name'] === 'notifications.notice'))[0];
        $this->assertSame('found', (new GraphQuery($index))->query($index->names[strtolower('App\\Action::send')][0], 'path', $view['id'], 8)['status']);
        $this->assertStringNotContainsString('-secret', json_encode([$index->elements, $index->relations]));
    }

    public function test_dynamic_via_and_private_channel_body_do_not_infer_delivery(): void
    {
        $index = new CatalogIndex($this->facts(['app/Notifications.php' => 'namespace App; class Dynamic extends \\Illuminate\\Notifications\\Notification { public function via($recipient) { return choose($recipient); } public function toMail($recipient) {} } class PrivateNotice extends \\Illuminate\\Notifications\\Notification { public function via($recipient) { return ["database"]; } private function toDatabase($recipient) {} public function toArray($recipient) {} } \\Illuminate\\Support\\Facades\\Notification::send($users, new Dynamic); \\Illuminate\\Support\\Facades\\Notification::sendNow($users, new PrivateNotice);']));
        $this->assertSame([], $this->edges($index, 'notification-delivery-hook'));
        $this->assertNotEmpty($index->diagnostics);
    }

    public function test_reused_notification_caller_and_via_refresh_channel_method_selection(): void
    {
        $caller = $this->facts(['app/Caller.php' => '\\Illuminate\\Support\\Facades\\Notification::send($users, new App\\Notice);'])[0];
        foreach (['toArray', 'toDatabase'] as $name) {
            $type = $this->facts(['app/Notice.php' => 'namespace App; class Notice extends \\Illuminate\\Notifications\\Notification { public function via($user) { return ["database"]; } public function '.$name.'($user) { return []; } }'])[0];
            $index = new CatalogIndex([CatalogFacts::fromArray($caller->path, $caller->toArray()), $type]);
            $hook = $this->edges($index, 'notification-delivery-hook');
            $this->assertCount(1, $hook);
            $this->assertSame($name, $hook[0]['metadata']['hook']);
        }
    }

    public function test_broadcast_hooks_require_an_object_dispatch_and_distinguish_worker_and_immediate(): void
    {
        $index = new CatalogIndex($this->facts(['app/Events.php' => <<<'SOURCE'
namespace App;
class Changed implements \Illuminate\Contracts\Broadcasting\ShouldBroadcast {
 public function broadcastWhen() { return allowed(); }
 public function broadcastOn() { return [new \Illuminate\Broadcasting\Channel('orders')]; }
 public function broadcastWith() { return ['secret' => 'payload-secret']; }
 public function unrelated() {}
}
class Immediate implements \Illuminate\Contracts\Broadcasting\ShouldBroadcastNow {
 public function broadcastOn() { return []; }
 public function shouldBroadcastNow() { return true; }
 public function broadcastQueue() { return 'unused'; }
}
class Decoy { public function broadcastOn() {} }
class Action {
 public function queued() { event(new Changed); }
 public function now() { event(new Immediate); }
 public function named() { event(Changed::class); }
 public function decoy() { event(new Decoy); }
}
SOURCE]));
        $hooks = $this->edges($index, 'broadcast-hook');
        $this->assertCount(4, $hooks);
        $this->assertSame(['broadcastWhen', 'broadcastOn', 'broadcastWith', 'broadcastOn'], array_column(array_column($hooks, 'metadata'), 'hook'));
        $this->assertSame(['broadcast-preparation', 'queue-worker-candidate', 'queue-worker-candidate', 'broadcast-delivery-candidate'], array_column(array_column($hooks, 'metadata'), 'execution_stage'));
        foreach ($hooks as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertStringNotContainsString('named', $index->elements[$edge['from']]['name']);
            $this->assertStringNotContainsString('decoy', $index->elements[$edge['from']]['name']);
        }
        $this->assertStringNotContainsString('payload-secret', json_encode([$index->elements, $index->relations]));
    }

    public function test_empty_broadcast_channel_return_does_not_reach_payload_hooks(): void
    {
        $index = new CatalogIndex($this->facts(['app/Event.php' => 'namespace App; class EmptyEvent implements \\Illuminate\\Contracts\\Broadcasting\\ShouldBroadcast { public function broadcastOn() { return []; } public function broadcastWith() { return []; } public function broadcastConnections() { return []; } } event(new EmptyEvent);']));
        $hooks = $this->edges($index, 'broadcast-hook');
        $this->assertCount(1, $hooks);
        $this->assertSame('broadcastOn', $hooks[0]['metadata']['hook']);
    }

    public function test_channel_subscription_authorization_is_separate_from_event_delivery(): void
    {
        $index = new CatalogIndex($this->facts([
            'routes/channels.php' => '\\Illuminate\\Support\\Facades\\Broadcast::channel("orders.{id}", fn ($user, $id) => App\\Permission::check($user, $id), ["guards" => ["web", "admin"]]); \\Illuminate\\Support\\Facades\\Broadcast::channel("team.{id}", App\\ChannelAuth::class);',
            'app/Types.php' => 'namespace App; class Permission { public static function check($user, $id) {} } trait Joins { public function join($user, $id) { return Permission::check($user, $id); } } class ChannelAuth { use Joins; } class Changed implements \\Illuminate\\Contracts\\Broadcasting\\ShouldBroadcast { public function broadcastOn() { return []; } } class Action { public function emit() { event(new Changed); } }',
        ]));
        $subscriptions = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'broadcast-subscription'));
        $this->assertCount(2, $subscriptions);
        $this->assertSame(['web', 'admin'], $subscriptions[0]['metadata']['guards']);
        $auth = $this->edges($index, 'channel-authorization');
        $this->assertCount(2, $auth);
        $this->assertEqualsCanonicalizing(['closure', 'method'], array_map(fn ($row) => $index->elements[$row['to']]['kind'], $auth));
        $query = new GraphQuery($index);
        $check = $index->names[strtolower('App\\Permission::check')][0];
        $this->assertSame('found', $query->query($subscriptions[0]['id'], 'path', $check, 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($index->names[strtolower('App\\Action::emit')][0], 'path', $check, 8)['status']);
        $impact = $query->query($check, 'impact', depth: 8);
        $this->assertCount(2, array_filter($impact['records'], fn ($row) => $row['entrypoint'] && $row['element']['kind'] === 'broadcast-subscription'));
        foreach ($auth as $edge) {
            $this->assertFalse($edge['metadata']['execution_proven']);
            $this->assertFalse($edge['metadata']['authorization_result_known']);
            $this->assertSame('subscription-authorization', $edge['metadata']['execution_stage']);
        }
    }

    public function test_channel_auth_private_dynamic_and_lookalike_do_not_infer_callbacks(): void
    {
        $index = new CatalogIndex($this->facts(['routes/channels.php' => 'class PrivateAuth { private function join($user) {} } class Broadcast { public static function channel($name, $callback) {} } \\Illuminate\\Support\\Facades\\Broadcast::channel("private", PrivateAuth::class); \\Illuminate\\Support\\Facades\\Broadcast::channel($dynamic, fn () => true); Broadcast::channel("decoy", fn () => true);']));
        $this->assertSame([], $this->edges($index, 'channel-authorization'));
        $this->assertCount(1, array_filter($index->elements, fn ($row) => $row['kind'] === 'broadcast-subscription'));
        $this->assertNotEmpty($index->diagnostics);
    }

    public function test_reused_channel_registration_refreshes_join_visibility_and_detects_facade_shadow(): void
    {
        $registration = $this->facts(['routes/channels.php' => '\\Illuminate\\Support\\Facades\\Broadcast::channel("orders.{id}", App\\Auth::class);'])[0];
        foreach (['public', 'private'] as $visibility) {
            $type = $this->facts(['app/Auth.php' => 'namespace App; class Auth { '.$visibility.' function join($user, $id) {} }'])[0];
            $index = new CatalogIndex([CatalogFacts::fromArray($registration->path, $registration->toArray()), $type]);
            $this->assertCount($visibility === 'public' ? 1 : 0, $this->edges($index, 'channel-authorization'));
        }
        $shadow = $this->facts(['app/Shadow.php' => 'namespace Illuminate\\Support\\Facades; class Broadcast {}'])[0];
        $index = new CatalogIndex([$registration, $type, $shadow]);
        $this->assertSame([], $this->edges($index, 'channel-authorization'));
        $this->assertNotEmpty($index->diagnostics);
    }

    public function test_broadcast_channels_are_resources_and_auth_template_links_are_structural(): void
    {
        $index = new CatalogIndex($this->facts([
            'routes/channels.php' => '\\Illuminate\\Support\\Facades\\Broadcast::channel("orders.{id}", fn () => App\\Permission::check());',
            'app/Event.php' => <<<'SOURCE'
namespace App;
use Illuminate\Broadcasting\PrivateChannel as PrivateTopic;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\Channel;
trait Topics { public function broadcastOn() { return [new PrivateTopic('orders.42'), new PresenceChannel(name: 'orders.42'), new Channel('news'), new \illuminate\broadcasting\channel('private-orders.42')]; } }
class Changed implements \Illuminate\Contracts\Broadcasting\ShouldBroadcast { use Topics; }
class Action { public function emit() { event(new Changed); } }
class Permission { public static function check() {} }
SOURCE,
        ]));
        $channels = array_values(array_filter($index->elements, fn ($row) => $row['kind'] === 'broadcast-channel'));
        $this->assertSame(['private-orders.42', 'presence-orders.42', 'news', 'private-orders.42'], array_column($channels, 'name'));
        $this->assertCount(4, $this->edges($index, 'broadcast-channel-target'));
        $this->assertNotSame($channels[0]['id'], $channels[3]['id']);
        $this->assertCount(2, $this->edges($index, 'references-channel-authorization'));
        $query = new GraphQuery($index);
        $start = $index->names[strtolower('App\\Action::emit')][0];
        $this->assertSame('found', $query->query($start, 'path', $channels[0]['id'], 8)['status']);
        $this->assertSame('no_path_in_analyzed_graph', $query->query($start, 'path', $index->names[strtolower('App\\Permission::check')][0], 8)['status']);
    }

    public function test_broadcast_channel_cache_recomposes_shadows_and_keeps_dynamic_selectors_unresolved(): void
    {
        $fixed = $this->facts(['app/Event.php' => <<<'SOURCE'
namespace App;
class Changed implements \Illuminate\Contracts\Broadcasting\ShouldBroadcast { public function broadcastOn() { return [new \Illuminate\Broadcasting\PrivateChannel('orders.42'), new \Illuminate\Broadcasting\Channel($this->secret)]; } }
class Decoy { public function broadcastOn() { return new \Illuminate\Broadcasting\Channel('decoy'); } }
SOURCE]);
        $fixed = array_map(fn ($row) => CatalogFacts::fromArray($row->path, $row->toArray()), $fixed);
        $index = new CatalogIndex($fixed);
        $this->assertCount(1, $this->edges($index, 'broadcast-channel-target'));
        $this->assertContains('broadcast_channel_analysis', array_column($index->diagnostics, 'code'));
        $shadow = $this->facts(['app/Shadow.php' => 'namespace Illuminate\\Broadcasting; class Channel {}'])[0];
        $this->assertSame([], $this->edges(new CatalogIndex([...$fixed, $shadow]), 'broadcast-channel-target'));
        $this->assertCount(1, $this->edges(new CatalogIndex($fixed), 'broadcast-channel-target'));
        $this->assertStringNotContainsString('secret', json_encode(array_map(fn ($row) => $row->toArray(), $fixed), JSON_THROW_ON_ERROR));
    }

    public function test_corrupted_cached_broadcast_channel_selector_is_rejected(): void
    {
        $facts = $this->facts(['app/Event.php' => 'class Changed { public function broadcastOn() { return new \\Illuminate\\Broadcasting\\Channel("orders"); } }'])[0];
        $value = $facts->toArray();
        foreach ($value['elements'] as &$element) {
            if (isset($element['metadata']['broadcast_channels'])) {
                $element['metadata']['broadcast_channels']['channels'][0]['name'] = 'orders?token=credential';
            }
        }
        unset($element);
        $this->expectException(\InvalidArgumentException::class);
        CatalogFacts::fromArray($facts->path, $value);
    }

    /** @param array<string, string> $sources
     * @return list<CatalogFacts>
     */
    private function facts(array $sources): array
    {
        $files = [];
        foreach ($sources as $path => $source) {
            $files[] = new FileContext($path, str_ends_with($path, '.blade.php') ? $source : '<?php '.$source);
        }

        return (new ProjectGraphBuilder(catalog: true))->build($files)->catalogFacts;
    }

    /** @return list<array<string, mixed>> */
    private function edges(CatalogIndex $index, string $kind): array
    {
        return array_values(array_filter($index->relations, fn ($row) => $row['kind'] === $kind));
    }
}
