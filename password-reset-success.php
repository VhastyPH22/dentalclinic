<?php
session_start();

// Clear reset session variables
unset($_SESSION['reset_email']);
unset($_SESSION['reset_verified']);
unset($_SESSION['code_timestamp']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Successful - San Francisco System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            padding: 60px 40px;
            width: 100%;
            max-width: 450px;
            text-align: center;
        }
        .success-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: scaleIn 0.5s ease;
        }
        @keyframes scaleIn {
            from {
                transform: scale(0);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }
        h1 {
            color: #27ae60;
            margin-bottom: 15px;
            font-size: 32px;
        }
        .message {
            color: #666;
            margin-bottom: 30px;
            font-size: 16px;
            line-height: 1.6;
        }
        .btn {
            display: inline-block;
            padding: 12px 40px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            transition: transform 0.2s;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .btn:active {
            transform: translateY(0);
        }
        .countdown {
            color: #999;
            font-size: 14px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">✓</div>
        <h1>Password Reset Successful!</h1>
        <p class="message">
            Your password has been successfully reset. You can now log in with your new password.
        </p>
        
        <a href="login.php" class="btn">Go to Login</a>
        
        <div class="countdown">
            Redirecting in <span id="countdown">5</span> seconds...
        </div>
    </div>

    <script>
        let seconds = 5;
        const countdownEl = document.getElementById('countdown');
        
        const interval = setInterval(() => {
            seconds--;
            countdownEl.textContent = seconds;
            
            if (seconds <= 0) {
                clearInterval(interval);
                window.location.href = 'login.php';
            }
        }, 1000);
    </script>
</body>
</html>
