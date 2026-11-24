<?php

namespace Benwilkins\FCM\Tests;

use Benwilkins\FCM\FcmChannel;
use Benwilkins\FCM\FcmMessage;
use Benwilkins\FCM\TokenManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Notifications\Notification;
use Mockery as m;

class FcmChannelTest extends TestCase
{
    private const PROJECT = 'test-project';

    /** @var array Guzzle request history */
    private $history = [];

    /** @var MockHandler */
    private $mock;

    /** @var FcmChannel */
    private $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->history = [];
        $this->mock = new MockHandler();
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        $tokenManager = m::mock(TokenManager::class);
        $tokenManager->shouldReceive('getAccessToken')->andReturn('access-token');

        $this->channel = new FcmChannel(new Client(['handler' => $stack]), $tokenManager, self::PROJECT);
    }

    private function ok(string $id = 'msg'): Response
    {
        return new Response(200, [], json_encode(['name' => 'projects/' . self::PROJECT . '/messages/' . $id]));
    }

    private function unregistered(): ClientException
    {
        return new ClientException(
            '404 UNREGISTERED',
            new Request('POST', 'https://fcm.googleapis.com'),
            new Response(404, [], '{"error":{"status":"NOT_FOUND"}}')
        );
    }

    /** @return array[] the decoded `message` of every request sent */
    private function sentMessages(): array
    {
        return array_map(function ($entry) {
            return json_decode((string) $entry['request']->getBody(), true)['message'];
        }, $this->history);
    }

    /** @return array tokens sent, one per request */
    private function sentTokens(): array
    {
        return array_column($this->sentMessages(), 'token');
    }

    public function test_a_single_token_is_sent_as_one_request_with_a_string_token(): void
    {
        $this->mock->append($this->ok());

        $result = $this->channel->send(new Notifiable(['token-a']), new TestNotification());

        $this->assertSame(['token-a'], $this->sentTokens());
        $this->assertIsString($this->sentMessages()[0]['token']);
        $this->assertSame('projects/test-project/messages/msg', $result[0]['name']);
    }

    public function test_multiple_tokens_are_sent_as_one_request_per_token(): void
    {
        $this->mock->append($this->ok('1'), $this->ok('2'), $this->ok('3'));

        $result = $this->channel->send(new Notifiable(['token-a', 'token-b', 'token-c']), new TestNotification());

        $this->assertSame(['token-a', 'token-b', 'token-c'], $this->sentTokens());
        $this->assertCount(3, $result);
        $this->assertSame('projects/test-project/messages/3', $result[2]['name']);
    }

    public function test_no_request_ever_carries_a_token_array(): void
    {
        // Regression: 6.3 sent {"token": ["a","b"]}, which the v1 API rejects with
        // 400 "Unknown name "token" at 'message'", so no device received anything.
        $this->mock->append($this->ok(), $this->ok());

        $this->channel->send(new Notifiable(['token-a', 'token-b']), new TestNotification());

        foreach ($this->sentMessages() as $message) {
            $this->assertIsString($message['token']);
        }
    }

    public function test_a_failing_token_does_not_stop_delivery_to_the_others(): void
    {
        $this->mock->append($this->unregistered(), $this->ok('live'));

        $result = $this->channel->send(new Notifiable(['dead-token', 'live-token']), new TestNotification());

        $this->assertSame(['dead-token', 'live-token'], $this->sentTokens());
        $this->assertArrayHasKey('error', $result[0]);
        $this->assertStringContainsString('UNREGISTERED', $result[0]['error']);
        $this->assertSame('projects/test-project/messages/live', $result[1]['name']);
    }

    public function test_a_failing_last_token_still_returns_earlier_results(): void
    {
        $this->mock->append($this->ok('live'), $this->unregistered());

        $result = $this->channel->send(new Notifiable(['live-token', 'dead-token']), new TestNotification());

        $this->assertCount(2, $result);
        $this->assertSame('projects/test-project/messages/live', $result[0]['name']);
        $this->assertArrayHasKey('error', $result[1]);
    }

    public function test_blank_null_and_duplicate_tokens_are_skipped(): void
    {
        $this->mock->append($this->ok(), $this->ok());

        $this->channel->send(new Notifiable(['token-a', '', 'token-a', null, 'token-b']), new TestNotification());

        $this->assertSame(['token-a', 'token-b'], $this->sentTokens());
    }

    public function test_a_string_route_is_sent_as_one_request(): void
    {
        $this->mock->append($this->ok());

        $this->channel->send(new Notifiable('token-a'), new TestNotification());

        $this->assertSame(['token-a'], $this->sentTokens());
    }

    public function test_nothing_is_sent_when_the_notifiable_has_no_tokens(): void
    {
        $this->assertNull($this->channel->send(new Notifiable([]), new TestNotification()));
        $this->assertNull($this->channel->send(new Notifiable(null), new TestNotification()));
        $this->assertCount(0, $this->history);
    }

    public function test_nothing_is_sent_when_every_token_is_blank(): void
    {
        $result = $this->channel->send(new Notifiable(['', null]), new TestNotification());

        $this->assertSame([], $result);
        $this->assertCount(0, $this->history);
    }

    public function test_a_recipient_set_on_the_message_overrides_the_route(): void
    {
        $this->mock->append($this->ok());
        $notifiable = new Notifiable(['route-token']);

        $this->channel->send($notifiable, new TestNotification('explicit-token'));

        $this->assertSame(['explicit-token'], $this->sentTokens());
        $this->assertSame(0, $notifiable->routeCalls);
    }

    public function test_every_per_token_request_carries_the_full_payload(): void
    {
        $this->mock->append($this->ok(), $this->ok());

        $this->channel->send(new Notifiable(['token-a', 'token-b']), new TestNotification());

        foreach ($this->sentMessages() as $message) {
            $this->assertSame(['title' => 'Customer', 'body' => 'Hello'], $message['notification']);
            $this->assertSame('App\\Notifications\\WhatsAppPushNotification', $message['data']['id']);
            $this->assertSame('8', $message['data']['salon_id']);
            $this->assertSame(FcmMessage::PRIORITY_HIGH, $message['android']['priority']);
            $this->assertSame('FLUTTER_NOTIFICATION_CLICK', $message['android']['notification']['click_action']);
        }
    }

    public function test_requests_go_to_the_v1_endpoint_with_the_access_token(): void
    {
        $this->mock->append($this->ok(), $this->ok());

        $this->channel->send(new Notifiable(['token-a', 'token-b']), new TestNotification());

        foreach ($this->history as $entry) {
            $request = $entry['request'];
            $this->assertSame('POST', $request->getMethod());
            $this->assertSame(
                'https://fcm.googleapis.com/v1/projects/test-project/messages:send',
                (string) $request->getUri()
            );
            $this->assertSame('Bearer access-token', $request->getHeaderLine('Authorization'));
        }
    }
}

class TestNotification extends Notification
{
    /** @var string|null */
    private $to;

    public function __construct(?string $to = null)
    {
        $this->to = $to;
    }

    public function toFcm($notifiable)
    {
        $message = (new FcmMessage())
            ->content(['title' => 'Customer', 'body' => 'Hello'])
            ->data(['id' => 'App\\Notifications\\WhatsAppPushNotification', 'salon_id' => '8'])
            ->priority(FcmMessage::PRIORITY_HIGH)
            ->clickAction('FLUTTER_NOTIFICATION_CLICK');

        if ($this->to) {
            $message->to($this->to);
        }

        return $message;
    }
}
