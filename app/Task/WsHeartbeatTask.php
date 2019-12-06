<?php
namespace App\Task;

use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Crontab\Annotation\Crontab;
use Hyperf\Di\Annotation\Inject;
use App\Constants\WebSocket;
use Hyperf\Redis\RedisFactory;
use Hyperf\Utils\ApplicationContext;
use Hyperf\WebSocketClient\ClientFactory;

/**
 * @Crontab(name="WsHeartbeatTask", rule="*\/5 * * * * *", callback="execute", memo="ws心跳检查")
 */
class WsHeartbeatTask
{

    /**
     * @Inject()
     * @var \Hyperf\Contract\StdoutLoggerInterface
     */
    private $logger;

    /**
     * @Inject
     * @var ClientFactory
     */
    protected $clientFactory;

    public function execute()
    {
        $redis = ApplicationContext::getContainer()
            ->get(RedisFactory::class)
            ->get(WebSocket::WEBSOCKET_CONNECTION_DATA_DRIVER_POOL);
        $nodeList = $redis->sMembers(WebSocket::WEBSOCKET_ALL_NODE_LIST);
        foreach ($nodeList as $uri) {
            try {
                // 通过 ClientFactory 创建 Client 对象，创建出来的对象为短生命周期对象
                $this->clientFactory->create('ws://'.$uri);
            } catch (\Throwable $e) {
                //清除redis数据中心
                $this->removeConnectionCenter($uri);
            }
        }
    }

    /**
     * 清除redis连接中心
     * @param string $uri
     */
    public function removeConnectionCenter(string $uri): void
    {
        $redis = ApplicationContext::getContainer()
            ->get(RedisFactory::class)
            ->get(WebSocket::WEBSOCKET_CONNECTION_DATA_DRIVER_POOL);
        try {
            $fdList = $redis->hGetAll(WebSocket::WEBSOCKET_CONNECTION_FD_HASH);
            if ($fdList) {
                foreach ($fdList as $fdStr => $uid) {
                    if (strpos($fdStr, $uri) !== false) {
                        $res = $redis->multi()
                            ->hDel(WebSocket::WEBSOCKET_CONNECTION_FD_HASH, $fdStr)
                            ->hDel(WebSocket::WEBSOCKET_CONNECTION_UID_HASH, $uid)
                            ->exec();
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
        }
    }
}
