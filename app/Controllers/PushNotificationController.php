<?php


namespace App\Controllers;

use App\Models\Country;
use App\Models\Device;
use App\Models\Notification;
use App\Models\PushNotification;

class PushNotificationController extends Controller
{
    protected array $errors = [];
    protected string $logFile = 'queue.json';

    /**
     * @api {post} / Request to send
     *
     * @apiVersion 0.1.0
     * @apiName send
     * @apiDescription This method saves the push notification and put it to the queue.
     * @apiGroup Sending
     *
     * @apiBody {string="send"} action API method
     * @apiBody {string} title Title of push notification
     * @apiBody {string} message Message of push notification
     * @apiBody {int} country_id Country ID
     *
     * @apiParamExample {json} Request-Example:
     * {"action":"send","title":"Hello","message":"World","country_id":4}
     *
     * @apiSuccessExample {json} Success:
     * {"success":true,"result":{"notification_id":123}}
     *
     * @apiErrorExample {json} Failed:
     * {"success":false,"result":null}
     */
    public function sendByCountryId(string $title, string $message, int $countryId): ?array
    {
        $data = $this->validate(compact('title', 'message'), [
            'title' => 'max:255',
            'message' => 'max:255',
        ]);

        $countryModel = new Country();
        $isCountryExists = $countryModel->newQuery()->where('id', $countryId)->exists();
        if (!$isCountryExists) {
            return null;
        }
        $notificationModel = new Notification();
        $data['country_id'] = $countryId;
        $data['status'] = Notification::STATUS_IN_QUEUE;
        $notificationId = $notificationModel->newQuery()->create($data);

        return [
            'notification_id' => $notificationId,
        ];
    }

    /**
     * @api {post} / Get details
     *
     * @apiVersion 0.1.0
     * @apiName details
     * @apiDescription This method returns all details by notification ID.
     * @apiGroup Information
     *
     * @apiBody {string="details"} action API method
     * @apiBody {int} notification_id Notification ID
     *
     * @apiParamExample {json} Request-Example:
     * {"action":"details","notification_id":123}
     *
     * @apiSuccessExample {json} Success:
     * {"success":true,"result":{"id":123,"title":"Hello","message":"World","sent":90000,"failed":10000,"in_progress":100000,"in_queue":123456}}
     *
     * @apiErrorExample {json} Notification not found:
     * {"success":false,"result":null}
     */
    public function details(int $notificationID): ?array
    {
        $notificationModel = new Notification();// TODO not good for test
        $columns = [
            'id',
            'title',
            'message',
            'sent',
            'failed',
            'in_progress',
            'in_queue',
        ];
        return $notificationModel->newQuery()->find($notificationID, $columns);
    }

    /**
     * @api {post} / Sending by CRON
     *
     * @apiVersion 0.1.0
     * @apiName cron
     * @apiDescription This method sends the push notifications from queue.
     * @apiGroup Sending
     *
     * @apiBody {string="cron"} action API method
     *
     * @apiParamExample {json} Request-Example:
     * {"action":"cron"}
     *
     * @apiSuccessExample {json} Success and sent:
     * {"success":true,"result":[{"notification_id":123,"title":"Hello","message":"World","sent":50000,"failed":10000},{"notification_id":124,"title":"New","message":"World","sent":20000,"failed":20000}]}
     *
     * @apiSuccessExample {json} Success, no notifications in the queue:
     * {"success":true,"result":[]}
     */
    public function cron(): array
    {
        // TODO for proper solution need use database transactions and lock
        $notificationModel = new Notification();// TODO not good for test
        $notifications = $notificationModel->newQuery()->where('status', '!=', Notification::STATUS_FINISHED)->get([
            'id',
            'country_id',
            'title',
            'message',
            'sent',
            'failed',
            'in_progress',
        ]);
        if (empty($notifications)) {
            return [];
        }
        $countryIds = $this->pluck($notifications, 'country_id');
        $countryIds = array_unique($countryIds);
        $deviceModel = new Device();// TODO not good for test
        $devices = $deviceModel->newQuery()
            ->join([
                'users' => [
                    'type' => 'INNER JOIN',
                    'own_key' => 'id',
                    'related_key' => 'id',
                ],
            ])
            ->where(['expired' => 0])
            ->whereIn('country_id', $countryIds)
            ->get(['token', 'user_id', 'users.country_id']);

        $countryDevices = [];
        foreach ($devices as $device) {
            $countryDevices[$device['country_id']][] = $device;
        }

        $logInfo = $this->getLog();
        $response = [];
        $countPerCron = config('PUSH_TO_N_DEVICES_BY_CRONE');
        foreach ($notifications as $notification) {
            $countryUserDevices = $countryDevices[$notification['country_id']] ?? [];
            $alreadySendUserIds = $logInfo[$notification['id']] ?? [];
            $needToSend = [];
            foreach ($countryUserDevices as $device) {
                if (!in_array($device['user_id'], $alreadySendUserIds)) {
                    $needToSend[] = $device;
                }
            }

            $notificationsInNextQueue = array_splice($needToSend, $countPerCron);
            $inProgress = count($needToSend);
            $inQueue = count($notificationsInNextQueue);
            $sent = 0;
            $failed = 0;

            if ($inQueue) {
                $logInfo[$notification['id']] = array_merge($alreadySendUserIds, $this->pluck($needToSend, 'user_id'));
            } else {
                unset($logInfo[$notification['id']]);
            }
            $this->updateLog($logInfo);
            foreach ($needToSend as $device) {
                if (PushNotification::send($notification['title'], $notification['message'], $device['token'])) {
                    $sent++;
                } else {
                    $failed++;
                }
            }

            $notificationModel->newQuery()->update($notification['id'], [
                'sent' => $notification['sent'] + $sent,
                'failed' => $notification['failed'] + $failed,
                'in_progress' => $notification['in_progress'] + $inProgress,
                'in_queue' => $inQueue,
                'status' => $inQueue ? Notification::STATUS_STARTED : Notification::STATUS_FINISHED
            ]);
            $response[] = [
                'notification_id' => $notification['id'],
                'title' => $notification['title'],
                'message' => $notification['message'],
                'sent' => $sent,
                'failed' => $failed,
            ];
        }

        return $response;
    }

    protected function validate(array $data, array $rules): array
    {
        $this->errors = [];
        $validated = [];
        foreach ($rules as $column => $_rules) {
            $value = $data[$column] ?? null;
            $inlineRules = explode('|', $_rules);
            foreach ($inlineRules as $rule) {
                $ruleParts = explode(':', $rule);
                if (!count($ruleParts) == 2) {
                    die('Not Developed case');
                }
                $ruleMethod = 'validate' . ucfirst($ruleParts[0]);
                $validate = $this->{$ruleMethod}($value, $ruleParts[1]);
                if ($validate) {
                    $validated[$column] = $value;
                }
            }
        }

        if (!empty($this->errors)) {
            validation_errors($this->errors);
        }

        return $validated;
    }

    protected function validateMax(string $value, int $max): bool
    {
        if (strlen($value) < $max) {
            return true;
        }
        $this->errors[$value][] = $value . ' must be smaller the: ' . $max;
        return false;
    }

    public function pluck(array $array, $column)
    {
        $pluck = [];
        foreach ($array as $item) {
            $pluck[] = $item[$column] ?? null;
        }
        return $pluck;
    }

    public function updateLog($data)
    {
        file_put_contents($this->logFile, json_encode($data));
    }

    public function getLog()
    {
        if (!file_exists($this->logFile)) {
            return [];
        }
        $content = file_get_contents($this->logFile);
        return json_decode($content, true);
    }
}