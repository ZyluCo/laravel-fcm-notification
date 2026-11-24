<?php

namespace Benwilkins\FCM\Tests;

use Benwilkins\FCM\FcmMessage;

class FcmMessageTest extends TestCase
{
    /** @var FcmMessage */
    protected $message;

    protected function setUp(): void
    {
        parent::setUp();
        $this->message = new FcmMessage();
    }

    private function payload(): array
    {
        return json_decode($this->message->formatData(), true)['message'];
    }

    public function test_it_has_default_android_priority(): void
    {
        $this->assertSame(FcmMessage::PRIORITY_NORMAL, $this->payload()['android']['priority']);
    }

    public function test_it_sets_high_android_priority(): void
    {
        $this->message->priority(FcmMessage::PRIORITY_HIGH);

        $this->assertSame(FcmMessage::PRIORITY_HIGH, $this->payload()['android']['priority']);
    }

    public function test_a_single_token_array_becomes_a_string(): void
    {
        $this->message->to(['token-a']);

        $this->assertSame('token-a', $this->message->getTo());
        $this->assertSame('token-a', $this->payload()['token']);
    }

    public function test_a_string_token_stays_a_string(): void
    {
        $this->message->to('token-a');

        $this->assertSame('token-a', $this->payload()['token']);
    }

    public function test_a_multi_token_array_is_kept_as_array_on_the_message(): void
    {
        // FcmMessage keeps the list; FcmChannel is responsible for splitting it,
        // because the v1 API only accepts one token per request.
        $this->message->to(['token-a', 'token-b']);

        $this->assertSame(['token-a', 'token-b'], $this->message->getTo());
    }

    public function test_it_formats_notification_data_and_click_action(): void
    {
        $this->message
            ->to('token-a')
            ->content(['title' => 'Title', 'body' => 'Body'])
            ->data(['id' => 'App\\Notifications\\WhatsAppPushNotification', 'salon_id' => '8'])
            ->clickAction('FLUTTER_NOTIFICATION_CLICK');

        $payload = $this->payload();

        $this->assertSame(['title' => 'Title', 'body' => 'Body'], $payload['notification']);
        $this->assertSame('8', $payload['data']['salon_id']);
        $this->assertSame('FLUTTER_NOTIFICATION_CLICK', $payload['android']['notification']['click_action']);
    }
}
