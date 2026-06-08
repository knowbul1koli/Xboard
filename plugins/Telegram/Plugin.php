<?php

namespace Plugin\Telegram;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Log;

class Plugin extends AbstractPlugin
{
  protected array $commands = [];
  protected TelegramService $telegramService;

  protected array $commandConfigs = [
    '/start'       => ['description' => '开始使用', 'handler' => 'handleStartCommand'],
    '/bind'        => ['description' => '绑定账号', 'handler' => 'handleBindCommand'],
    '/traffic'     => ['description' => '查看流量', 'handler' => 'handleTrafficCommand'],
    '/getlatesturl'=> ['description' => '获取订阅链接', 'handler' => 'handleGetLatestUrlCommand'],
    '/unbind'      => ['description' => '解绑账号', 'handler' => 'handleUnbindCommand'],
  ];

  protected array $adminCommandConfigs = [
    '/stats'        => ['description' => '[管理] 查看站点统计', 'handler' => 'handleStatsCommand'],
    '/users'        => ['description' => '[管理] 查看所有用户 /users [页码]', 'handler' => 'handleUsersCommand'],
    '/lookup'       => ['description' => '[管理] 查询用户 /lookup 邮箱', 'handler' => 'handleLookupCommand'],
    '/ban'          => ['description' => '[管理] 封禁用户 /ban 邮箱', 'handler' => 'handleBanCommand'],
    '/unban'        => ['description' => '[管理] 解封用户 /unban 邮箱', 'handler' => 'handleUnbanCommand'],
    '/tgusers'      => ['description' => '[管理] 已绑定TG的用户列表 /tgusers [页码]', 'handler' => 'handleTgUsersCommand'],
    '/resettraffic' => ['description' => '[管理] 重置流量 /resettraffic 邮箱', 'handler' => 'handleResetTrafficCommand'],
    '/setplan'      => ['description' => '[管理] 修改套餐 /setplan 邮箱 套餐ID', 'handler' => 'handleSetPlanCommand'],
    '/addbalance'   => ['description' => '[管理] 增加余额 /addbalance 邮箱 金额', 'handler' => 'handleAddBalanceCommand'],
    '/tickets'      => ['description' => '[管理] 待处理工单列表', 'handler' => 'handleTicketsCommand'],
    '/replyticket'  => ['description' => '[管理] 回复工单 /replyticket 工单ID 内容', 'handler' => 'handleReplyTicketCommand'],
    '/closeticket'  => ['description' => '[管理] 关闭工单 /closeticket 工单ID', 'handler' => 'handleCloseTicketCommand'],
    '/orders'       => ['description' => '[管理] 最近订单 /orders [页码]', 'handler' => 'handleOrdersCommand'],
    '/plans'        => ['description' => '[管理] 查看所有套餐', 'handler' => 'handlePlansCommand'],
    '/nodes'        => ['description' => '[管理] 节点列表', 'handler' => 'handleNodesCommand'],
    '/nodeinfo'     => ['description' => '[管理] 节点详情 /nodeinfo 节点ID', 'handler' => 'handleNodeInfoCommand'],
    '/groups'       => ['description' => '[管理] 权限组列表', 'handler' => 'handleGroupsCommand'],
    '/routes'       => ['description' => '[管理] 路由规则列表', 'handler' => 'handleRoutesCommand'],
    '/coupons'      => ['description' => '[管理] 优惠券列表 /coupons [页码]', 'handler' => 'handleCouponsCommand'],
    '/addcoupon'    => ['description' => '[管理] 创建优惠券（引导流程）', 'handler' => 'handleAddCouponCommand'],
    '/giftcards'    => ['description' => '[管理] 礼品卡模板列表', 'handler' => 'handleGiftCardsCommand'],
    '/gengiftcode'  => ['description' => '[管理] 生成礼品卡码 /gengiftcode 模板ID 数量', 'handler' => 'handleGenGiftCodeCommand'],
  ];

  public function boot(): void
  {
    $this->telegramService = new TelegramService();
    $this->registerDefaultCommands();
    $this->filter('telegram.message.handle', [$this, 'handleMessage'], 10);
    $this->listen('telegram.message.unhandled', [$this, 'handleUnknownCommand'], 10);
    $this->listen('telegram.message.error', [$this, 'handleError'], 10);
    $this->filter('telegram.bot.commands', [$this, 'addBotCommands'], 10);
    $this->listen('ticket.create.after', [$this, 'sendTicketNotify'], 10);
    $this->listen('ticket.reply.user.after', [$this, 'sendTicketNotify'], 10);
    $this->listen('payment.notify.success', [$this, 'sendPaymentNotify'], 10);
  }

  public function sendPaymentNotify(Order $order): void
  {
    if (!$this->getConfig('enable_payment_notify', true)) return;
    $payment = $order->payment;
    if (!$payment) { Log::warning('支付通知失败：订单关联的支付方式不存在', ['order_id' => $order->id]); return; }
    $message = sprintf("💰成功收款%s元\n支付接口：%s\n支付渠道：%s\n本站订单：`%s`",
      $order->total_amount / 100,
      Helper::escapeMarkdown($payment->payment),
      Helper::escapeMarkdown($payment->name),
      $order->trade_no
    );
    $this->telegramService->sendMessageWithAdmin($message, true);
  }

  public function sendTicketNotify(Ticket $ticket): void
  {
    if (!$this->getConfig('enable_ticket_notify', true)) return;
    $message = $ticket->messages()->latest()->first();
    $user = User::find($ticket->user_id);
    if (!$user) return;
    $user->load('plan');
    $transfer_enable = $this->transferToGBString($user->transfer_enable);
    $remaining_traffic = $this->transferToGBString($user->transfer_enable - $user->u - $user->d);
    $u = $this->transferToGBString($user->u);
    $d = $this->transferToGBString($user->d);
    $expired_at = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '长期有效';
    $money = $user->balance / 100;
    $affmoney = $user->commission_balance / 100;
    $plan = $user->plan;
    $TGmessage = "📮 *工单提醒* #{$ticket->id}\n";
    $TGmessage .= "📧 邮箱: `{$user->email}`\n";
    if ($plan) {
      $TGmessage .= "📦 套餐: `" . Helper::escapeMarkdown($plan->name) . "`\n";
      $TGmessage .= "📊 流量: `{$remaining_traffic}G / {$transfer_enable}G`\n";
      $TGmessage .= "⬆️⬇️ 已用: `{$u}G / {$d}G`\n";
      $TGmessage .= "⏰ 到期: `{$expired_at}`\n";
    } else {
      $TGmessage .= "📦 套餐: `未订购任何套餐`\n";
    }
    $TGmessage .= "💰 余额: `{$money}元`\n";
    $TGmessage .= "💸 佣金: `{$affmoney}元`\n";
    $TGmessage .= "📝 *主题*: `" . Helper::escapeMarkdown($ticket->subject) . "`\n";
    $TGmessage .= "💬 *内容*: `" . Helper::escapeMarkdown($message->message) . "`";
    $this->telegramService->sendMessageWithAdmin($TGmessage, true);
  }

  protected function registerDefaultCommands(): void
  {
    foreach ($this->commandConfigs as $command => $config) {
      $this->registerTelegramCommand($command, [$this, $config['handler']]);
    }
    foreach ($this->adminCommandConfigs as $command => $config) {
      $this->registerTelegramCommand($command, [$this, $config['handler']]);
    }
    $this->registerReplyHandler('/(📮.*?工单提醒.*?#?|工单ID: ?)(\d+)/', [$this, 'handleTicketReply']);
  }

  public function registerTelegramCommand(string $command, callable $handler): void
  {
    $this->commands['commands'][$command] = $handler;
  }

  public function registerReplyHandler(string $regex, callable $handler): void
  {
    $this->commands['replies'][$regex] = $handler;
  }

  protected function sendMessage(object $msg, string $message): void
  {
    $this->telegramService->sendMessage($msg->chat_id, $message, 'markdown');
  }

  protected function checkPrivateChat(object $msg): bool
  {
    if (!$msg->is_private) { $this->sendMessage($msg, '请在私聊中使用此命令'); return false; }
    return true;
  }

  protected function getBoundUser(object $msg): ?User
  {
    $user = User::where('telegram_id', $msg->chat_id)->first();
    if (!$user) { $this->sendMessage($msg, '请先绑定账号'); return null; }
    return $user;
  }

  protected function checkAdmin(object $msg): bool
  {
    $user = User::where('telegram_id', $msg->chat_id)->first();
    if (!$user || !$user->is_admin) { $this->sendMessage($msg, '❌ 无权限，此命令仅管理员可用'); return false; }
    return true;
  }

  public function handleStartCommand(object $msg): void
  {
    $welcomeTitle = $this->getConfig('start_welcome_title', '🎉 欢迎使用 XBoard Telegram Bot！');
    $botDescription = $this->getConfig('start_bot_description', '🤖 我是您的专属助手');
    $footer = $this->getConfig('start_footer', '💡 提示：所有命令都需要在私聊中使用');
    $welcomeText = $welcomeTitle . "\n\n" . $botDescription . "\n\n";
    $user = User::where('telegram_id', $msg->chat_id)->first();
    if ($user) {
      $welcomeText .= "✅ 您已绑定账号：{$user->email}\n\n";
      $welcomeText .= $this->getConfig('start_unbind_guide', '📋 可用命令：\n/traffic - 查看流量\n/getlatesturl - 获取订阅链接\n/unbind - 解绑账号');
    } else {
      $welcomeText .= $this->getConfig('start_bind_guide', '🔗 请先绑定账号：\n发送 /bind + 订阅链接') . "\n\n";
      $welcomeText .= $this->getConfig('start_bind_commands', '📋 可用命令：\n/bind [订阅链接] - 绑定账号');
    }
    $welcomeText .= "\n\n" . $footer;
    $welcomeText = str_replace('\\n', "\n", $welcomeText);
    $this->sendMessage($msg, $welcomeText);
  }

  public function handleMessage(bool $handled, array $data): bool
  {
    list($msg) = $data;
    if ($handled) return $handled;
    try {
      return match ($msg->message_type) {
        'message' => $this->handleCommandMessage($msg),
        'reply_message' => $this->handleReplyMessage($msg),
        default => false
      };
    } catch (\Exception $e) {
      Log::error('Telegram 命令处理意外错误', ['command' => $msg->command ?? 'unknown', 'error' => $e->getMessage()]);
      if (isset($msg->chat_id)) $this->telegramService->sendMessage($msg->chat_id, '系统繁忙，请稍后重试');
      return true;
    }
  }

  protected function handleCommandMessage(object $msg): bool
  {
    if (!isset($this->commands['commands'][$msg->command])) return false;
    call_user_func($this->commands['commands'][$msg->command], $msg);
    return true;
  }

  protected function handleReplyMessage(object $msg): bool
  {
    if (!isset($this->commands['replies'])) return false;
    foreach ($this->commands['replies'] as $regex => $handler) {
      if (preg_match($regex, $msg->reply_text, $matches)) {
        call_user_func($handler, $msg, $matches);
        return true;
      }
    }
    return false;
  }

  public function handleUnknownCommand(array $data): void
  {
    list($msg) = $data;
    if (!$msg->is_private || $msg->message_type !== 'message') return;
    $helpText = $this->getConfig('help_text', '未知命令，请查看帮助');
    $this->telegramService->sendMessage($msg->chat_id, $helpText);
  }

  public function handleError(array $data): void
  {
    list($msg, $e) = $data;
    Log::error('Telegram 消息处理错误', ['chat_id' => $msg->chat_id ?? 'unknown', 'error' => $e->getMessage()]);
  }

  public function handleBindCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;
    $subscribeUrl = $msg->args[0] ?? null;
    if (!$subscribeUrl) { $this->sendMessage($msg, '参数有误，请携带订阅地址发送'); return; }
    $token = $this->extractTokenFromUrl($subscribeUrl);
    if (!$token) { $this->sendMessage($msg, '订阅地址无效'); return; }
    $user = User::where('token', $token)->first();
    if (!$user) { $this->sendMessage($msg, '用户不存在'); return; }
    if ($user->telegram_id) { $this->sendMessage($msg, '该账号已经绑定了Telegram账号'); return; }
    $user->telegram_id = $msg->chat_id;
    if (!$user->save()) { $this->sendMessage($msg, '设置失败'); return; }
    HookManager::call('user.telegram.bind.after', [$user]);
    $this->sendMessage($msg, '绑定成功');
  }

  protected function extractTokenFromUrl(string $url): ?string
  {
    $parsedUrl = parse_url($url);
    if (isset($parsedUrl['query'])) {
      parse_str($parsedUrl['query'], $query);
      if (isset($query['token'])) return $query['token'];
    }
    if (isset($parsedUrl['path'])) {
      $pathParts = explode('/', trim($parsedUrl['path'], '/'));
      $lastPart = end($pathParts);
      return $lastPart ?: null;
    }
    return null;
  }

  public function handleTrafficCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;
    $user = $this->getBoundUser($msg);
    if (!$user) return;
    $transferUsed = $user->u + $user->d;
    $transferTotal = $user->transfer_enable;
    $transferRemaining = $transferTotal - $transferUsed;
    $usagePercentage = $transferTotal > 0 ? ($transferUsed / $transferTotal) * 100 : 0;
    $text = sprintf("📊 流量使用情况\n\n已用流量：%sG\n总流量：%sG\n剩余流量：%sG\n使用率：%.2f%%",
      $this->transferToGBString($transferUsed),
      $this->transferToGBString($transferTotal),
      $this->transferToGBString($transferRemaining),
      $usagePercentage
    );
    $this->sendMessage($msg, $text);
  }

  public function handleGetLatestUrlCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;
    $user = $this->getBoundUser($msg);
    if (!$user) return;
    $subscribeUrl = Helper::getSubscribeUrl($user->token);
    $this->sendMessage($msg, "🔗 您的订阅链接：\n\n{$subscribeUrl}");
  }

  public function handleUnbindCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) return;
    $user = $this->getBoundUser($msg);
    if (!$user) return;
    $user->telegram_id = null;
    if (!$user->save()) { $this->sendMessage($msg, '解绑失败'); return; }
    $this->sendMessage($msg, '解绑成功');
  }

  public function handleTicketReply(object $msg, array $matches): void
  {
    $user = $this->getBoundUser($msg);
    if (!$user) return;
    if (!isset($matches[2]) || !is_numeric($matches[2])) { $this->sendMessage($msg, '未能识别工单ID'); return; }
    $ticketId = (int) $matches[2];
    $ticket = Ticket::where('id', $ticketId)->first();
    if (!$ticket) { $this->sendMessage($msg, '工单不存在'); return; }
    $ticketService = new TicketService();
    $ticketService->replyByAdmin($ticketId, $msg->text, $user->id);
    $this->sendMessage($msg, "工单 #{$ticketId} 回复成功");
  }

  public function addBotCommands(array $commands): array
  {
    foreach ($this->commandConfigs as $command => $config) {
      $commands[] = ['command' => $command, 'description' => $config['description']];
    }
    foreach ($this->adminCommandConfigs as $command => $config) {
      $commands[] = ['command' => $command, 'description' => $config['description']];
    }
    return $commands;
  }

  private function transferToGBString(float $transfer_enable, int $decimals = 2): string
  {
    return number_format(Helper::transferToGB($transfer_enable), $decimals, '.', '');
  }

  // ==================== 管理员命令 ====================

  public function handleStatsCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $tz = new \DateTimeZone('Asia/Shanghai');
    $now = new \DateTime('now', $tz);
    $todayStart = (clone $now)->setTime(0,0,0)->getTimestamp();
    $monthStart = (clone $now)->modify('first day of this month')->setTime(0,0,0)->getTimestamp();
    $dateStr = $now->format('Y-m-d H:i');
    $totalUsers      = User::count();
    $activeUsers     = User::whereNotNull('plan_id')->where('banned', 0)->where(function($q) { $q->whereNull('expired_at')->orWhere('expired_at', '>', time()); })->count();
    $bannedUsers     = User::where('banned', 1)->count();
    $telegramUsers   = User::whereNotNull('telegram_id')->count();
    $totalBalance    = round(User::sum('balance') / 100, 2);
    $totalCommission = round(User::sum('commission_balance') / 100, 2);
    $totalNodes      = \App\Models\Server::count();
    $onlineNodes     = \App\Models\Server::where('show', 1)->count();
    $totalOrders     = \App\Models\Order::where('status', 3)->count();
    $totalRevenue    = round(\App\Models\Order::where('status', 3)->sum('total_amount') / 100, 2);
    $totalDiscount   = round(\App\Models\Order::where('status', 3)->sum('discount_amount') / 100, 2);
    $totalCommOrders = \App\Models\Order::where('commission_status', '>', 0)->count();
    $totalCommPaid   = round(\App\Models\Order::where('commission_status', 2)->sum('actual_commission_balance') / 100, 2);
    $todayOrders     = \App\Models\Order::where('status', 3)->where('paid_at', '>=', $todayStart)->count();
    $todayRevenue    = round(\App\Models\Order::where('status', 3)->where('paid_at', '>=', $todayStart)->sum('total_amount') / 100, 2);
    $todayDiscount   = round(\App\Models\Order::where('status', 3)->where('paid_at', '>=', $todayStart)->sum('discount_amount') / 100, 2);
    $todayReg        = User::where('created_at', '>=', $todayStart)->count();
    $todayComm       = round(\App\Models\Order::where('commission_status', 2)->where('paid_at', '>=', $todayStart)->sum('actual_commission_balance') / 100, 2);
    $monthOrders     = \App\Models\Order::where('status', 3)->where('paid_at', '>=', $monthStart)->count();
    $monthRevenue    = round(\App\Models\Order::where('status', 3)->where('paid_at', '>=', $monthStart)->sum('total_amount') / 100, 2);
    $monthDiscount   = round(\App\Models\Order::where('status', 3)->where('paid_at', '>=', $monthStart)->sum('discount_amount') / 100, 2);
    $monthReg        = User::where('created_at', '>=', $monthStart)->count();
    $monthComm       = round(\App\Models\Order::where('commission_status', 2)->where('paid_at', '>=', $monthStart)->sum('actual_commission_balance') / 100, 2);
    $todayStat  = \App\Models\Stat::where('record_type', 'd')->orderBy('record_at', 'desc')->first();
    $monthStat  = \App\Models\Stat::where('record_type', 'm')->orderBy('record_at', 'desc')->first();
    $text  = "📊 站点统计 {$dateStr}\n\n";
    $text .= "👤 用户\n  总用户：{$totalUsers} 人\n  有效用户：{$activeUsers} 人\n  封禁：{$bannedUsers} 人\n  绑定TG：{$telegramUsers} 人\n\n";
    $text .= "💳 用户资产\n  余额合计：{$totalBalance} 元\n  佣金余额合计：{$totalCommission} 元\n\n";
    $text .= "🖥 节点\n  上线：{$onlineNodes} 个 / 总计：{$totalNodes} 个\n\n";
    $text .= "📦 历史总计\n  完成订单：{$totalOrders} 笔\n  总收入：{$totalRevenue} 元\n  总优惠折扣：{$totalDiscount} 元\n  佣金订单：{$totalCommOrders} 笔\n  已结算佣金：{$totalCommPaid} 元\n\n";
    $text .= "📅 今日\n  完成订单：{$todayOrders} 笔\n  收入：{$todayRevenue} 元\n  优惠折扣：{$todayDiscount} 元\n  新注册：{$todayReg} 人\n  结算佣金：{$todayComm} 元\n";
    if ($todayStat) $text .= "  流量消耗：" . round($todayStat->transfer_used_total / 1073741824, 2) . " GB\n";
    $text .= "\n📆 本月\n  完成订单：{$monthOrders} 笔\n  收入：{$monthRevenue} 元\n  优惠折扣：{$monthDiscount} 元\n  新注册：{$monthReg} 人\n  结算佣金：{$monthComm} 元\n";
    if ($monthStat) $text .= "  流量消耗：" . round($monthStat->transfer_used_total / 1073741824, 2) . " GB";
    $this->sendMessage($msg, $text);
  }

  public function handleUsersCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $page  = (int)($msg->args[0] ?? 1);
    $users = User::with('plan')->orderBy('created_at', 'desc')->paginate(5, ['*'], 'page', $page);
    $total = $users->total();
    $pages = (int)ceil($total / 5);
    $text  = "👥 用户列表 第{$page}/{$pages}页（共{$total}人）\n\n";
    foreach ($users as $u) {
      if ($u->banned) $status = '🚫 封禁';
      elseif (!$u->plan_id) $status = '⏳ 无套餐';
      elseif (!$u->expired_at) $status = '✅ 有效（永久）';
      elseif ($u->expired_at > time()) $status = '✅ 有效';
      else $status = '⏰ 已过期';
      $expired = $u->expired_at ? date('Y-m-d', $u->expired_at) : '永久';
      $plan    = $u->plan ? $u->plan->name : '无套餐';
      $balance = round($u->balance / 100, 2);
      $used    = round(($u->u + $u->d) / 1073741824, 2);
      $total_t = $u->transfer_enable ? round($u->transfer_enable / 1073741824, 2) : 0;
      $tg = $u->telegram_id ? "📱 TG：已绑定 (ID:{$u->telegram_id})" : "📱 TG：未绑定";
      $text .= "{$status}\n  📧 {$u->email}\n  📦 套餐：{$plan}\n  ⏰ 到期：{$expired}\n  📊 流量：{$used}GB / {$total_t}GB\n  💰 余额：{$balance} 元\n  {$tg}\n\n";
    }
    if ($page < $pages) $text .= "发送 /users " . ($page + 1) . " 查看下一页";
    $this->sendMessage($msg, $text);
  }

  public function handleLookupCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $email = $msg->args[0] ?? null;
    if (!$email) { $this->sendMessage($msg, '请提供邮箱：/lookup 邮箱'); return; }
    $user = User::where('email', $email)->with('plan')->first();
    if (!$user) { $this->sendMessage($msg, '❌ 用户不存在'); return; }
    $tz = new \DateTimeZone('Asia/Shanghai');
    if ($user->banned) $status = '🚫 封禁';
    elseif (!$user->plan_id) $status = '⏳ 无套餐';
    elseif (!$user->expired_at) $status = '✅ 有效（永久）';
    elseif ($user->expired_at > time()) $status = '✅ 有效';
    else $status = '⏰ 已过期';
    $expired   = $user->expired_at ? (new \DateTime('@'.$user->expired_at))->setTimezone($tz)->format('Y-m-d') : '永久';
    $createdAt = (new \DateTime('@'.$user->created_at))->setTimezone($tz)->format('Y-m-d H:i');
    $lastLogin = $user->last_login_at ? (new \DateTime('@'.$user->last_login_at))->setTimezone($tz)->format('Y-m-d H:i') : '—';
    $balance    = round($user->balance / 100, 2);
    $commission = round($user->commission_balance / 100, 2);
    $usedU      = round(($user->u ?? 0) / 1073741824, 2);
    $usedD      = round(($user->d ?? 0) / 1073741824, 2);
    $usedTotal  = round($usedU + $usedD, 2);
    $totalGB    = $user->transfer_enable ? round($user->transfer_enable / 1073741824, 2) : 0;
    $remaining  = round($totalGB - $usedTotal, 2);
    $percent    = $totalGB > 0 ? round($usedTotal / $totalGB * 100, 1) : 0;
    $plan       = $user->plan ? $user->plan->name : '无套餐';
    $speed      = $user->speed_limit ? $user->speed_limit . ' Mbps' : '不限';
    $device     = $user->device_limit ? $user->device_limit . ' 台' : '不限';
    $totalOrderCount  = \App\Models\Order::where('user_id', $user->id)->where('status', 3)->count();
    $totalOrderAmount = round(\App\Models\Order::where('user_id', $user->id)->where('status', 3)->sum('total_amount') / 100, 2);
    $inviteCount      = User::where('invite_user_id', $user->id)->count();
    $text  = "🔍 用户信息\n\n";
    $text .= "📧 邮箱：{$user->email}\n🔖 状态：{$status}\n📅 注册：{$createdAt}\n🕐 最后登录：{$lastLogin}\n\n";
    $text .= "📦 套餐：{$plan}\n⏰ 到期：{$expired}\n⚡ 限速：{$speed}\n💻 设备数：{$device}\n\n";
    $text .= "📊 流量\n  ↑上行：{$usedU} GB\n  ↓下行：{$usedD} GB\n  已用：{$usedTotal} GB / {$totalGB} GB ({$percent}%)\n  剩余：{$remaining} GB\n\n";
    $text .= "💰 余额：{$balance} 元\n💸 佣金余额：{$commission} 元\n\n";
    $text .= "🛒 历史订单：{$totalOrderCount} 笔 / {$totalOrderAmount} 元\n👥 邀请用户：{$inviteCount} 人\n";
    $text .= $user->telegram_id ? "📱 TG：已绑定 (ID:{$user->telegram_id})\n" : "📱 TG：未绑定\n";
    if ($user->is_admin) $text .= "\n👑 管理员账号";
    $this->sendMessage($msg, $text);
  }

  public function handleBanCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $email = $msg->args[0] ?? null;
    if (!$email) { $this->sendMessage($msg, "用法：/ban 邮箱\n示例：/ban user@example.com"); return; }
    $user = User::where('email', $email)->with('plan')->first();
    if (!$user) { $this->sendMessage($msg, '❌ 用户不存在'); return; }
    if ($user->banned) { $this->sendMessage($msg, '⚠️ 该用户已处于封禁状态'); return; }
    $user->banned = 1; $user->save();
    $plan = $user->plan ? $user->plan->name : '无套餐';
    $this->sendMessage($msg, "✅ 封禁成功\n\n📧 {$email}\n📦 套餐：{$plan}\n🚫 状态：已封禁");
  }

  public function handleUnbanCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $email = $msg->args[0] ?? null;
    if (!$email) { $this->sendMessage($msg, "用法：/unban 邮箱\n示例：/unban user@example.com"); return; }
    $user = User::where('email', $email)->with('plan')->first();
    if (!$user) { $this->sendMessage($msg, '❌ 用户不存在'); return; }
    if (!$user->banned) { $this->sendMessage($msg, '⚠️ 该用户未被封禁'); return; }
    $user->banned = 0; $user->save();
    $plan = $user->plan ? $user->plan->name : '无套餐';
    $this->sendMessage($msg, "✅ 解封成功\n\n📧 {$email}\n📦 套餐：{$plan}\n✅ 状态：正常");
  }

  public function handleTgUsersCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $page    = (int)($msg->args[0] ?? 1);
    $perPage = 8;
    $users   = User::whereNotNull('telegram_id')
                   ->with('plan')
                   ->orderBy('created_at', 'desc')
                   ->paginate($perPage, ['*'], 'page', $page);
    $total = $users->total();
    $pages = (int)ceil($total / $perPage);
    if ($total === 0) {
      $this->sendMessage($msg, '📭 暂无绑定 Telegram 的用户');
      return;
    }
    $text = "📱 TG绑定用户 第{$page}/{$pages}页（共{$total}人）\n\n";
    foreach ($users as $u) {
      if ($u->banned) $status = '🚫 封禁';
      elseif (!$u->plan_id) $status = '⏳ 无套餐';
      elseif (!$u->expired_at) $status = '✅ 永久';
      elseif ($u->expired_at > time()) $status = '✅ 有效';
      else $status = '⏰ 已过期';
      $plan    = $u->plan ? $u->plan->name : '无套餐';
      $expired = $u->expired_at ? date('Y-m-d', $u->expired_at) : '永久';
      $used    = round(($u->u + $u->d) / 1073741824, 2);
      $total_t = $u->transfer_enable ? round($u->transfer_enable / 1073741824, 2) : 0;
      $text .= "{$status}  📧 {$u->email}\n";
      $text .= "  🆔 TG ID：{$u->telegram_id}\n";
      $text .= "  📦 {$plan}  ⏰ {$expired}\n";
      $text .= "  📊 {$used}GB / {$total_t}GB\n\n";
    }
    if ($page < $pages) $text .= "发送 /tgusers " . ($page + 1) . " 查看下一页";
    $this->sendMessage($msg, $text);
  }

  public function handleAddBalanceCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $email  = $msg->args[0] ?? null;
    $amount = $msg->args[1] ?? null;
    if (!$email || !$amount || !is_numeric($amount)) {
      $this->sendMessage($msg, "用法：/addbalance 邮箱 金额\n支持负数（扣减余额）\n示例：/addbalance user@example.com 50"); return;
    }
    $user = User::where('email', $email)->first();
    if (!$user) { $this->sendMessage($msg, '❌ 用户不存在'); return; }
    $before = round($user->balance / 100, 2);
    $user->balance += (int)((float)$amount * 100); $user->save();
    $after = round($user->balance / 100, 2);
    $change = (float)$amount > 0 ? "+{$amount}" : $amount;
    $this->sendMessage($msg, "✅ 余额调整成功\n\n📧 {$email}\n💰 调整前：{$before} 元\n💸 调整：{$change} 元\n💰 调整后：{$after} 元");
  }

  public function handleResetTrafficCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $email = $msg->args[0] ?? null;
    if (!$email) { $this->sendMessage($msg, "用法：/resettraffic 邮箱\n示例：/resettraffic user@example.com"); return; }
    $user = User::where('email', $email)->first();
    if (!$user) { $this->sendMessage($msg, '❌ 用户不存在'); return; }
    $usedBefore = round(($user->u + $user->d) / 1073741824, 2);
    $user->u = 0; $user->d = 0; $user->save();
    $this->sendMessage($msg, "✅ 流量重置成功\n\n📧 {$email}\n📊 重置前已用：{$usedBefore} GB\n📊 重置后已用：0 GB");
  }

  public function handleSetPlanCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $email  = $msg->args[0] ?? null;
    $planId = $msg->args[1] ?? null;
    if (!$email || !$planId) {
      $plans = \App\Models\Plan::orderBy('sort')->get();
      $help  = "用法：/setplan 邮箱 套餐ID\n\n可用套餐：\n";
      foreach ($plans as $p) $help .= "  [{$p->id}] {$p->name}\n";
      $this->sendMessage($msg, $help); return;
    }
    $user = User::where('email', $email)->with('plan')->first();
    if (!$user) { $this->sendMessage($msg, '❌ 用户不存在'); return; }
    $plan = \App\Models\Plan::find((int)$planId);
    if (!$plan) { $this->sendMessage($msg, '❌ 套餐不存在'); return; }
    $oldPlan = $user->plan ? $user->plan->name : '无套餐';
    $user->plan_id = $plan->id; $user->save();
    $this->sendMessage($msg, "✅ 套餐修改成功\n\n📧 {$email}\n📦 原套餐：{$oldPlan}\n📦 新套餐：{$plan->name}");
  }

  public function handlePlansCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $plans = \App\Models\Plan::orderBy('sort')->get();
    $text = "📦 套餐列表\n\n";
    foreach ($plans as $p) {
      $prices = $p->prices ?? [];
      $priceStr = '';
      if (!empty($prices['monthly']))     $priceStr .= "月付：" . $prices['monthly'] . "元  ";
      if (!empty($prices['quarterly']))   $priceStr .= "季付：" . $prices['quarterly'] . "元  ";
      if (!empty($prices['half_yearly'])) $priceStr .= "半年：" . $prices['half_yearly'] . "元  ";
      if (!empty($prices['yearly']))      $priceStr .= "年付：" . $prices['yearly'] . "元  ";
      if (!empty($prices['two_yearly']))  $priceStr .= "两年：" . $prices['two_yearly'] . "元  ";
      if (!empty($prices['onetime']))     $priceStr .= "一次性：" . $prices['onetime'] . "元";
      $status = $p->show ? '✅' : '❌';
      $sold   = $p->sell ? '销售中' : '已下架';
      $cap    = $p->capacity_limit ? "限{$p->capacity_limit}人" : '不限';
      $text .= "{$status} [{$p->id}] {$p->name}\n";
      $text .= "  流量：{$p->transfer_enable}GB  设备：" . ($p->device_limit ?? '不限') . "台  限速：" . ($p->speed_limit ? $p->speed_limit . "Mbps" : '不限') . "\n";
      $text .= "  {$sold}  容量：{$cap}\n";
      $text .= "  {$priceStr}\n\n";
    }
    $this->sendMessage($msg, $text);
  }

  public function handleNodesCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $page    = (int)($msg->args[0] ?? 1);
    $perPage = 8;
    $all     = \App\Models\Server::orderBy('sort')->get();
    $total   = $all->count();
    $pages   = (int)ceil($total / $perPage);
    $page    = max(1, min($page, $pages));
    $servers = $all->forPage($page, $perPage);
    $text = "🖥 节点列表 第{$page}/{$pages}页（共{$total}个）\n\n";
    foreach ($servers as $s) {
      $status  = $s->show ? '✅' : '❌';
      $usedGB  = round((($s->u ?? 0) + ($s->d ?? 0)) / 1073741824, 2);
      $totalGB = round(($s->transfer_enable ?? 0) / 1073741824, 2);
      $upGB    = round(($s->u ?? 0) / 1073741824, 2);
      $downGB  = round(($s->d ?? 0) / 1073741824, 2);
      $text .= "{$status} [{$s->id}] {$s->name}\n  类型:{$s->type}  倍率:{$s->rate}x\n  ↑{$upGB}GB ↓{$downGB}GB / 限额:{$totalGB}GB\n\n";
    }
    if ($page < $pages) $text .= "发送 /nodes " . ($page + 1) . " 查看下一页";
    $this->sendMessage($msg, $text);
  }

  public function handleNodeInfoCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $id = $msg->args[0] ?? null;
    if (!$id) { $this->sendMessage($msg, "用法：/nodeinfo 节点ID\n发送 /nodes 查看节点列表"); return; }
    $server = \App\Models\Server::find((int)$id);
    if (!$server) { $this->sendMessage($msg, '❌ 节点不存在'); return; }
    $status  = $server->show ? '✅ 上线' : '❌ 下线';
    $upGB    = round(($server->u ?? 0) / 1073741824, 2);
    $downGB  = round(($server->d ?? 0) / 1073741824, 2);
    $usedGB  = round($upGB + $downGB, 2);
    $totalGB = round(($server->transfer_enable ?? 0) / 1073741824, 2);
    $percent = $totalGB > 0 ? round($usedGB / $totalGB * 100, 1) : 0;
    $groupIds = is_array($server->group_ids) ? implode(', ', $server->group_ids) : '—';
    $text  = "🖥 节点详情\n\nID：{$server->id}\n名称：{$server->name}\n状态：{$status}\n类型：{$server->type}\n地址：{$server->host}\n端口：{$server->port}\n倍率：{$server->rate}x\n权限组：{$groupIds}\n\n";
    $text .= "📊 流量\n  ↑上行：{$upGB} GB\n  ↓下行：{$downGB} GB\n  已用：{$usedGB} GB / {$totalGB} GB ({$percent}%)";
    $this->sendMessage($msg, $text);
  }

  public function handleGroupsCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $groups = \App\Models\ServerGroup::all();
    if ($groups->isEmpty()) { $this->sendMessage($msg, '暂无权限组'); return; }
    $text = "👥 权限组列表（共{$groups->count()}个）\n\n";
    foreach ($groups as $g) {
      $planCount = \App\Models\Plan::where('group_id', $g->id)->count();
      $nodeCount = \App\Models\Server::whereJsonContains('group_ids', (string)$g->id)->count();
      $planIds   = \App\Models\Plan::where('group_id', $g->id)->pluck('id')->toArray();
      $userCount = empty($planIds) ? 0 : User::whereIn('plan_id', $planIds)->where(function($q) { $q->whereNull('expired_at')->orWhere('expired_at', '>', time()); })->count();
      $text .= "🔖 [{$g->id}] {$g->name}\n  套餐数：{$planCount} 个\n  节点数：{$nodeCount} 个\n  有效用户：{$userCount} 人\n\n";
    }
    $this->sendMessage($msg, $text);
  }

  public function handleRoutesCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $routes = \App\Models\ServerRoute::all();
    if ($routes->isEmpty()) { $this->sendMessage($msg, '暂无路由规则'); return; }
    $actionMap = ['proxy' => '🔀 代理', 'direct' => '🟢 直连', 'block' => '🚫 屏蔽'];
    $text = "🛣 路由规则列表（共{$routes->count()}条）\n\n";
    foreach ($routes as $r) {
      $action     = $actionMap[$r->action] ?? $r->action;
      $matchCount = is_array($r->match) ? count($r->match) : 0;
      $matchStr   = is_array($r->match) ? implode(', ', array_slice($r->match, 0, 3)) : '';
      if ($matchCount > 3) $matchStr .= " 等{$matchCount}条";
      $text .= "📌 [{$r->id}] {$r->remarks}\n  动作：{$action}";
      if ($r->action_value) $text .= " → {$r->action_value}";
      $text .= "\n  规则数：{$matchCount} 条\n";
      if ($matchStr) $text .= "  包含：{$matchStr}\n";
      $text .= "\n";
    }
    $this->sendMessage($msg, $text);
  }

  public function handleOrdersCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $page    = (int)($msg->args[0] ?? 1);
    $perPage = 5;
    $orders  = \App\Models\Order::with(['user', 'plan'])->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);
    $total   = $orders->total();
    $pages   = (int)ceil($total / $perPage);
    $tz      = new \DateTimeZone('Asia/Shanghai');
    $statusMap = [0 => '⏳待支付', 1 => '🔄开通中', 2 => '❌已取消', 3 => '✅已完成', 4 => '💸折扣中'];
    $periodMap = ['monthly'=>'月付','quarterly'=>'季付','half_yearly'=>'半年付','yearly'=>'年付','two_yearly'=>'两年付','three_yearly'=>'三年付','onetime'=>'一次性','reset_traffic'=>'重置流量'];
    $text = "📋 订单列表 第{$page}/{$pages}页（共{$total}笔）\n\n";
    foreach ($orders as $o) {
      $status    = $statusMap[$o->status] ?? '未知';
      $email     = $o->user ? $o->user->email : '未知';
      $plan      = $o->plan ? $o->plan->name : '未知套餐';
      $period    = $periodMap[$o->period] ?? $o->period;
      $amount    = round($o->total_amount / 100, 2);
      $discount  = $o->discount_amount ? round($o->discount_amount / 100, 2) : 0;
      $paidAt    = $o->paid_at ? (new \DateTime('@'.$o->paid_at))->setTimezone($tz)->format('m-d H:i') : '—';
      $createdAt = (new \DateTime('@'.$o->created_at))->setTimezone($tz)->format('m-d H:i');
      $text .= "{$status}\n  📧 {$email}\n  📦 {$plan} / {$period}\n  💰 {$amount} 元";
      if ($discount > 0) $text .= "（优惠 {$discount} 元）";
      $text .= "\n  🕐 创建：{$createdAt}";
      if ($o->paid_at) $text .= "  支付：{$paidAt}";
      $text .= "\n  🔖 {$o->trade_no}\n\n";
    }
    if ($page < $pages) $text .= "发送 /orders " . ($page + 1) . " 查看下一页";
    $this->sendMessage($msg, $text);
  }

  public function handleTicketsCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $tickets = \App\Models\Ticket::where('status', 0)->with('user')->orderBy('created_at', 'desc')->get();
    if ($tickets->isEmpty()) { $this->sendMessage($msg, '✅ 暂无待处理工单'); return; }
    $tz   = new \DateTimeZone('Asia/Shanghai');
    $text = "📮 待处理工单（共{$tickets->count()}个）\n\n";
    foreach ($tickets as $t) {
      $email     = $t->user ? $t->user->email : '未知';
      $createdAt = (new \DateTime('@'.$t->created_at))->setTimezone($tz)->format('m-d H:i');
      $levelMap  = [0=>'🟢低', 1=>'🟡中', 2=>'🔴高'];
      $level     = $levelMap[$t->level ?? 0] ?? '🟢低';
      $text .= "🎫 #{$t->id} {$level}\n  📧 {$email}\n  📝 {$t->subject}\n  🕐 {$createdAt}\n  回复：/replyticket {$t->id} 内容\n  关闭：/closeticket {$t->id}\n\n";
    }
    $this->sendMessage($msg, $text);
  }

  public function handleCloseTicketCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $id = $msg->args[0] ?? null;
    if (!$id) { $this->sendMessage($msg, "用法：/closeticket 工单ID\n示例：/closeticket 123"); return; }
    $ticket = \App\Models\Ticket::with('user')->find((int)$id);
    if (!$ticket) { $this->sendMessage($msg, '❌ 工单不存在'); return; }
    if ($ticket->status == 1) { $this->sendMessage($msg, '⚠️ 该工单已关闭'); return; }
    $ticket->status = 1; $ticket->save();
    $email = $ticket->user ? $ticket->user->email : '未知';
    $this->sendMessage($msg, "✅ 工单已关闭\n\n🎫 #{$id}\n📧 {$email}\n📝 {$ticket->subject}");
  }

  public function handleReplyTicketCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $id    = $msg->args[0] ?? null;
    $reply = implode(' ', array_slice($msg->args, 1));
    if (!$id || !$reply) { $this->sendMessage($msg, "用法：/replyticket 工单ID 回复内容\n示例：/replyticket 123 您好，问题已处理"); return; }
    $ticket = \App\Models\Ticket::with('user')->find((int)$id);
    if (!$ticket) { $this->sendMessage($msg, '❌ 工单不存在'); return; }
    $admin = User::where('telegram_id', $msg->chat_id)->first();
    $ticketService = new TicketService();
    $ticketService->replyByAdmin((int)$id, $reply, $admin->id);
    $email = $ticket->user ? $ticket->user->email : '未知';
    $this->sendMessage($msg, "✅ 回复成功\n\n🎫 #{$id}\n📧 {$email}\n💬 {$reply}");
  }

  public function handleCouponsCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $page    = (int)($msg->args[0] ?? 1);
    $perPage = 8;
    $coupons = \App\Models\Coupon::orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);
    $total   = $coupons->total();
    $pages   = (int)ceil($total / $perPage);
    $tz      = new \DateTimeZone('Asia/Shanghai');
    $now     = time();
    $text = "🎟 优惠券列表 第{$page}/{$pages}页（共{$total}张）\n\n";
    foreach ($coupons as $c) {
      if ($c->type == 2) $discount = "减 {$c->value}%";
      else $discount = "减 " . round($c->value / 100, 2) . " 元";
      $used    = $c->use_count ?? 0;
      $limit   = $c->limit_use ?? '∞';
      $perUser = $c->limit_use_with_user ? "每人限{$c->limit_use_with_user}次" : '不限每人';
      $show    = $c->show ? '✅显示' : '🔒隐藏';
      if (!$c->started_at && !$c->ended_at) {
        $validity = '永久有效';
      } else {
        $start = $c->started_at ? (new \DateTime('@'.$c->started_at))->setTimezone($tz)->format('Y-m-d') : '—';
        $end   = $c->ended_at  ? (new \DateTime('@'.$c->ended_at))->setTimezone($tz)->format('Y-m-d')  : '—';
        $validity = ($c->ended_at && $c->ended_at < $now) ? "❌已过期\n    {$start} ~ {$end}" : "{$start} ~ {$end}";
      }
      $plans = !empty($c->limit_plan_ids) ? "\n  限套餐ID：" . implode(',', $c->limit_plan_ids) : '';
      $text .= "🎫 {$c->name}\n  码：{$c->code}\n  折扣：{$discount}\n  使用：{$used} / {$limit}  {$perUser}\n  有效期：{$validity}\n  状态：{$show}{$plans}\n\n";
    }
    if ($page < $pages) $text .= "发送 /coupons " . ($page + 1) . " 查看下一页";
    $this->sendMessage($msg, $text);
  }

  public function handleAddCouponCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $code   = $msg->args[0] ?? null;
    $type   = (int)($msg->args[1] ?? 0);
    $value  = $msg->args[2] ?? null;
    $limit  = (int)($msg->args[3] ?? 0);
    if (!$code || !$type || !$value) {
      $help = "用法：/addcoupon 优惠码 类型 值 限制次数\n\n类型：\n1 = 固定减免金额（元）\n2 = 折扣百分比（如 10 = 减10%）\n\n示例：\n/addcoupon VIP50 1 50 0\n（VIP50 优惠码，减50元，不限次数）\n\n/addcoupon SUMMER10 2 10 100\n（SUMMER10 优惠码，减10%，限100次）";
      $this->sendMessage($msg, $help); return;
    }
    if (!in_array($type, [1, 2])) { $this->sendMessage($msg, '类型错误，1=固定金额 2=折扣百分比'); return; }
    if (\App\Models\Coupon::where('code', $code)->first()) { $this->sendMessage($msg, "❌ 优惠码 `{$code}` 已存在"); return; }
    $realValue = $type == 1 ? (int)($value * 100) : (int)$value;
    \App\Models\Coupon::create(['code' => $code, 'type' => $type, 'value' => $realValue, 'limit_use' => $limit ?: null, 'use_count' => 0, 'started_at' => null, 'ended_at' => null]);
    $typeStr  = $type == 2 ? "{$value}% 折扣" : "减 {$value} 元";
    $limitStr = $limit ? "限 {$limit} 次" : "不限次数";
    $this->sendMessage($msg, "✅ 优惠券创建成功\n\n码：`{$code}`\n类型：{$typeStr}\n限制：{$limitStr}");
  }

  public function handleGiftCardsCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $templates = \App\Models\GiftCardTemplate::all();
    if ($templates->isEmpty()) { $this->sendMessage($msg, '暂无礼品卡模板'); return; }
    $text = "🎁 礼品卡模板列表（共{$templates->count()}个）\n\n";
    foreach ($templates as $t) {
      $value  = round($t->value / 100, 2);
      $total  = \App\Models\GiftCardCode::where('template_id', $t->id)->count();
      $used   = \App\Models\GiftCardCode::where('template_id', $t->id)->where('status', 1)->count();
      $remain = $total - $used;
      $text .= "🎫 [{$t->id}] {$t->name}\n  面值：{$value} 元\n  总数：{$total} 张\n  已用：{$used} 张\n  剩余：{$remain} 张\n  生成：/gengiftcode {$t->id} 数量\n\n";
    }
    $this->sendMessage($msg, $text);
  }

  public function handleGenGiftCodeCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg) || !$this->checkAdmin($msg)) return;
    $templateId = $msg->args[0] ?? null;
    $count      = (int)($msg->args[1] ?? 0);
    if (!$templateId || !$count) {
      $templates = \App\Models\GiftCardTemplate::all();
      $help = "用法：/gengiftcode 模板ID 数量\n最多一次生成20张\n\n可用模板：\n";
      foreach ($templates as $t) $help .= "  [{$t->id}] {$t->name} 面值:" . round($t->value/100,2) . "元\n";
      $this->sendMessage($msg, $help); return;
    }
    $template = \App\Models\GiftCardTemplate::find((int)$templateId);
    if (!$template) { $this->sendMessage($msg, '❌ 模板不存在'); return; }
    $count = min($count, 20);
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
      $code = strtoupper(substr(md5(uniqid(rand(), true)), 0, 16));
      \App\Models\GiftCardCode::create(['template_id' => $template->id, 'code' => $code, 'status' => 0]);
      $codes[] = $code;
    }
    $value = round($template->value / 100, 2);
    $text  = "✅ 已生成 {$count} 张礼品卡\n模板：{$template->name}  面值：{$value} 元\n\n" . implode("\n", $codes);
    $this->sendMessage($msg, $text);
  }

}
