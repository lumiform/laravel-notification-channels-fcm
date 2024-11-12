<?php

namespace NotificationChannels\Fcm;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Notification;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Message;
use NotificationChannels\Fcm\Exceptions\CouldNotSendNotification;
use Psr\Log\LoggerInterface;
use ReflectionException;
use Throwable;

class FcmChannel
{
    const MAX_TOKEN_PER_REQUEST = 500;

    /**
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected $events;

    protected LoggerInterface $logger;

    /**
     * FcmChannel constructor.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $dispatcher
     */
    public function __construct(Dispatcher $dispatcher, LoggerInterface $logger)
    {
        $this->events = $dispatcher;
        $this->logger = $logger;
    }

    /**
     * @var string|null
     */
    protected $fcmProject = null;

    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return array
     *
     * @throws \NotificationChannels\Fcm\Exceptions\CouldNotSendNotification
     * @throws \Kreait\Firebase\Exception\FirebaseException
     */
    public function send($notifiable, Notification $notification)
    {
        $token = $notifiable->routeNotificationFor('fcm', $notification);

        if (empty($token)) {
            return [];
        }

        // Get the message from the notification class
        $fcmMessage = $notification->toFcm($notifiable);

        if (! $fcmMessage instanceof Message) {
            throw CouldNotSendNotification::invalidMessage();
        }

        $this->fcmProject = null;
        if (method_exists($notification, 'fcmProject')) {
            $this->fcmProject = $notification->fcmProject($notifiable, $fcmMessage);
        }

        $responses = [];
        $errors = [];
        if (!is_array($token)) {
            $token = [$token];
        }

        foreach ($token as $singleToken) {
            try {
                $responses[] = $this->sendToFcm($fcmMessage, $singleToken);
            } catch (MessagingException $exception) {
                $this->failedNotification($notifiable, $notification, $exception, $token);
                $errors[] = CouldNotSendNotification::serviceRespondedWithAnError($exception);
            }
        }

        foreach ($errors as $error) {
            $previousErrors = $error->getPrevious()?->errors();
            $details = [];
            $isTokenError = false;

            if (count($previousErrors) && isset($previousErrors['error'])) {
                $previousError = $previousErrors['error'];
                $details = $previousError['details'];
                $isTokenError = isset($details[1]['fieldViolations'][0]['field'])
                    && $details[1]['fieldViolations'][0]['field'] === 'message.token';
            }

            $this->logger->info('FCM error: ' . $error->getMessage(), ['details' => $details, 'isTokenError' => $isTokenError ]);
        }

        return $responses;
    }

    /**
     * @return \Kreait\Firebase\Messaging
     */
    protected function messaging()
    {
        try {
            $messaging = app('firebase.manager')->project($this->fcmProject)->messaging();
        } catch (BindingResolutionException $e) {
            $messaging = app('firebase.messaging');
        } catch (ReflectionException $e) {
            $messaging = app('firebase.messaging');
        }

        return $messaging;
    }

    /**
     * @param  \Kreait\Firebase\Messaging\Message  $fcmMessage
     * @param $token
     * @return array
     *
     * @throws \Kreait\Firebase\Exception\MessagingException
     * @throws \Kreait\Firebase\Exception\FirebaseException
     */
    protected function sendToFcm(Message $fcmMessage, $token)
    {
        if ($fcmMessage instanceof CloudMessage) {
            $fcmMessage = $fcmMessage->withChangedTarget('token', $token);
        }

        if ($fcmMessage instanceof FcmMessage) {
            $fcmMessage->setToken($token);
        }

        return $this->messaging()->send($fcmMessage);
    }

    /**
     * @param $fcmMessage
     * @param  array  $tokens
     * @return \Kreait\Firebase\Messaging\MulticastSendReport
     *
     * @throws \Kreait\Firebase\Exception\MessagingException
     * @throws \Kreait\Firebase\Exception\FirebaseException
     */
    protected function sendToFcmMulticast($fcmMessage, array $tokens)
    {
        return $this->messaging()->sendMulticast($fcmMessage, $tokens);
    }

    /**
     * Dispatch failed event.
     *
     * @param  mixed  $notifiable
     * @param  \Illuminate\Notifications\Notification  $notification
     * @param  \Throwable  $exception
     * @param  string|array  $token
     * @return array|null
     */
    protected function failedNotification($notifiable, Notification $notification, Throwable $exception, $token)
    {
        return $this->events->dispatch(new NotificationFailed(
            $notifiable,
            $notification,
            self::class,
            [
                'message' => $exception->getMessage(),
                'exception' => $exception,
                'token' => $token,
            ]
        ));
    }
}
