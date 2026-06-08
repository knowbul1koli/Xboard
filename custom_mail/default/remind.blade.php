<div style="background-color: #f4f6f9; padding: 40px 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); overflow: hidden;">
        
        <div style="background-color: #dc3545; padding: 25px 30px; text-align: center;">
            <h1 style="color: #ffffff; margin: 0; font-size: 24px; font-weight: 600; letter-spacing: 1px;">
                {{ $name }} - 账户重要提醒
            </h1>
        </div>

        <div style="padding: 30px; color: #333333; line-height: 1.6; font-size: 16px;">
            <p style="margin-top: 0;">尊敬的用户，您好：</p>
            
            <div style="background-color: #fff3cd; border-left: 4px solid #ffc107; padding: 15px 20px; margin: 25px 0; border-radius: 0 4px 4px 0; color: #856404;">
                {{ $content ?? "" }}
            </div>

            <p style="margin-bottom: 0;">请您及时登录网站查看详情或进行续费，以免服务中断影响您的正常使用。</p>
            
            <div style="text-align: center; margin-top: 30px;">
                <a href="{{ config('app.url') }}" style="display: inline-block; background-color: #007bff; color: #ffffff; padding: 12px 25px; text-decoration: none; border-radius: 4px; font-weight: bold;">立即登录个人中心</a>
            </div>
        </div>

        <div style="background-color: #f8f9fa; padding: 20px 30px; text-align: center; border-top: 1px solid #eeeeee;">
            <p style="color: #888888; font-size: 13px; margin: 0;">
                此邮件由系统自动发送，请勿直接回复。<br>
                &copy; {{ date('Y') }} <a href="{{ config('app.url') }}" style="color: #007bff; text-decoration: none;">{{ $name }}</a>. All rights reserved.
            </p>
        </div>

    </div>
</div>