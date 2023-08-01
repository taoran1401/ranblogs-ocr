<?php
namespace App\Service;

use App\Amqp\Producer\IntegralProducer;
use App\Constants\IntegralLogType;
use App\Model\ApiProduct;
use App\Model\IntegralLog;
use App\Model\OpenapiProduct;
use App\Model\Users;
use Hyperf\Amqp\Producer;
use Hyperf\Di\Annotation\Inject;
use Hyperf\Redis\Redis;

class UserService
{
    /**
     * @Inject()
     * @var Redis
     */
    public $redis;

    /**
     * @Inject()
     * @var Producer
     */
    public $producer;

    public function getInfo($userId)
    {
        $users = Users::select(['id', 'nick_name', 'phone', 'email', 'avatar', 'status', 'integral', 'invite_code', 'other_invite_code', 'created_at'])->find($userId);
        if (!$users) {
            throw new \Exception('用户信息异常');
        }
        //签到状态
        $users->check_in = 0;
        if (!$this->isCheckIn($users->id)) {
            //未签到
            $users->check_in = 1;
        }

        return $users;
    }

    /**
     * 用户积分或openapi产品配置检测等
     *
     * @param $userId
     * @param $openapiCode
     * @param $openapiinfo
     * @throws \Exception
     */
    public function checkExecOpenapi($userId, $openapiCode, &$openapiinfo)
    {
        $this->checkOpenapiValid($openapiCode);

        $usersExists = Users::where('id', $userId)
            ->where('integral', '>=', $openapiinfo->integral)
            ->exists();
        if (!$usersExists) {
            throw new \Exception('积分不足');
        }
    }

    public function checkOpenapiValid($openapiCode)
    {
        $openapiinfo = OpenapiProduct::where('code', $openapiCode)->first();
        if (!$openapiinfo) {
            throw new \Exception('openapi product error');
        }
    }

    /**
     * 签到
     *
     * @param $userId
     * @throws \Exception
     */
    public function checkIn($userId)
    {
        //签到的积分流水信息同时也是签到的信息
        if (!$this->isCheckIn($userId)) {
            throw new \Exception('请勿重复签到');
        }
        //签到发放积分
        $this->producer->produce(new IntegralProducer(['type' => IntegralLogType::CHECK_IN, 'user_id' => $userId]));
    }

    /**
     * 是否签到
     */
    public function isCheckIn($userId)
    {
        $time = time();
        $startTime = date('Y-m-d 00:00:00', $time);
        $endTime = date('Y-m-d 23:59:59', $time);
        $count = IntegralLog::where('user_id', $userId)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->where('type', IntegralLogType::CHECK_IN)
            ->count();
        if ($count > 0) {
            //已经签到
            return false;
        }
        //未签到
        return true;
    }

    /**
     * 分享发送积分
     *
     * @param $userId
     */
    public function share($userId)
    {
        $time = time();
        $startTime = date('Y-m-d 00:00:00', $time);
        $endTime = date('Y-m-d 23:59:59', $time);
        $count = IntegralLog::where('user_id', $userId)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->where('type', IntegralLogType::SHARE)
            ->count();
        if ($count == 0) {
            $this->producer->produce(new IntegralProducer(['type' => IntegralLogType::SHARE, 'user_id' => $userId]));
        }
    }
}