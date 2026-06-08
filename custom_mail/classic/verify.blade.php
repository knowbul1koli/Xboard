<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>验证码</title>
    <style>
        /* 手机端响应式优化 */
        @media screen and (max-width: 600px) {
            .wrapper { padding: 15px 0 !important; }
            .container { width: 100% !important; border-radius: 0 !important; box-shadow: none !important; }
            .content-box { padding: 20px 15px !important; }
            .code-text { font-size: 28px !important; letter-spacing: 4px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f6f9; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">
    <div class="wrapper" style="background-color:#f4f6f9; padding:40px 20px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
        <div class="container" style="max-width:600px; margin:0 auto; background:#fff; border-radius:8px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); overflow:hidden;">
            
            <div style="background:#007bff; padding:25px; text-align:center;">
                <h1 style="color:#fff; margin:0; font-size: 22px;">
                    {{ $name ?? config('app.name') }}
                </h1>
            </div>

            <div class="content-box" style="padding:30px; font-size:16px; color:#333; line-height: 1.6;">
                <p style="margin-top: 0;">您的验证码：</p>
                <div class="code-text" style="font-size:32px; font-weight:bold; letter-spacing:6px; text-align:center; margin:30px 0; color: #007bff;">
                    {{ $code ?? '------' }}
                </div>
                <p style="margin-bottom: 0; color: #666; font-size: 14px; text-align: center;">5分钟内有效，请勿泄露给他人。</p>
            </div>
        </div>
    </div>
</body>
</html>