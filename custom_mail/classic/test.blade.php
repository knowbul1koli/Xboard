<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="zh-CN">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <style type="text/css">
        @media only screen and (max-width: 600px) {
            .main-card { width: 100% !important; border-radius: 0 !important; }
            .content-padding { padding: 25px 20px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f6f9fc; font-family: -apple-system, system-ui, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
    <table border="0" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td align="center" style="padding: 40px 10px;">
                <table border="0" cellpadding="0" cellspacing="0" width="600" class="main-card" style="background-color: #ffffff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                    
                    <tr>
                        <td align="left" style="padding: 30px 40px; border-bottom: 1px solid #f0f0f0;">
                            <a href="{{ config('app.url') }}" style="text-decoration: none;">
                                <span style="font-size: 20px; font-weight: 800; color: #007bff; letter-spacing: -0.5px;">{{ $name }}</span>
                            </a>
                        </td>
                    </tr>

                    <tr>
                        <td class="content-padding" style="padding: 40px; color: #333333; line-height: 1.6; font-size: 16px;">
                            <div style="margin: 0;">
                                {!! nl2br($content ?? "") !!}
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td align="left" style="padding: 25px 40px; background-color: #fafafa; border-radius: 0 0 12px 12px;">
                            <table border="0" cellpadding="0" cellspacing="0" width="100%">
                                <tr>
                                    <td>
                                        <p style="color: #aaaaaa; font-size: 12px; margin: 0;">
                                            &copy; {{ date('Y') }} {{ $name }}. 本邮件由系统自动发出。
                                        </p>
                                    </td>
                                    <td align="right">
                                        <a href="{{ config('app.url') }}" style="color: #007bff; font-size: 12px; text-decoration: none; font-weight: 600;">个人中心 &rarr;</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>