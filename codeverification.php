<?php
session_start();

// Check if user came from forgot password page
if (!isset($_SESSION['reset_email'])) {
    header("Location: forgotpassword.php");
    exit();
}

// Debug: show what's in session
// echo "Session email: " . $_SESSION['reset_email'] . "<br>";
// echo "Session code timestamp: " . $_SESSION['code_timestamp'] . "<br>";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code - San Francisco System</title>
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
            padding: 40px;
            width: 100%;
            max-width: 400px;
        }
        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
            text-align: center;
        }
        .subtitle {
            color: #666;
            text-align: center;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            color: #333;
            margin-bottom: 8px;
            font-weight: 500;
        }
        .code-input {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 24px;
            text-align: center;
            letter-spacing: 10px;
            font-weight: bold;
        }
        .code-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.1);
        }
        .btn {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
        }
        .btn:active {
            transform: translateY(0);
        }
        .error-message {
            color: #e74c3c;
            font-size: 13px;
            margin-top: 8px;
            padding: 10px;
            background: #fadbd8;
            border-radius: 5px;
            display: none;
        }
        .success-message {
            color: #27ae60;
            font-size: 13px;
            margin-top: 8px;
            padding: 10px;
            background: #d5f4e6;
            border-radius: 5px;
            display: none;
        }
        .resend-link {
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
        }
        .resend-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .resend-link a:hover {
            text-decoration: underline;
        }
        .back-link {
            text-align: center;
            margin-top: 15px;
        }
        .back-link a {
            color: #666;
            text-decoration: none;
            font-size: 13px;
        }
        .back-link a:hover {
            color: #333;
        }
        .loading {
            display: none;
            text-align: center;
            margin-top: 10px;
        }
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            margin: 0 auto;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Verify Code</h1>
        <p class="subtitle">Enter the 6-digit code sent to your email</p>
        
        <form id="verifyForm">
            <div class="form-group">
                <label for="code">Verification Code</label>
                <input 
                    type="text" 
                    id="code" 
                    name="code" 
                    class="code-input" 
                    placeholder="000000" 
                    maxlength="6" 
                    inputmode="numeric"
                    required
                >
                <div class="error-message" id="errorMessage"></div>
                <div class="success-message" id="successMessage"></div>
            </div>
            
            <button type="submit" class="btn">Verify Code</button>
            <div class="loading" id="loading">
                <div class="spinner"></div>
            </div>
        </form>
        
        <div class="resend-link">
            <a href="forgotpassword.php">Didn't receive the code? Resend</a>
        </div>
        
        <div class="back-link">
            <a href="login.php">Back to Login</a>
        </div>
    </div>

    <script>
        document.getElementById('code').addEventListener('input', function(e) {
            // Only allow numbers
            e.target.value = e.target.value.replace(/[^0-9]/g, '');
        });

        document.getElementById('verifyForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const code = document.getElementById('code').value.trim();
            const errorDiv = document.getElementById('errorMessage');
            const successDiv = document.getElementById('successMessage');
            const loadingDiv = document.getElementById('loading');
            const submitBtn = this.querySelector('button[type="submit"]');
            
            // Reset messages
            errorDiv.style.display = 'none';
            successDiv.style.display = 'none';
            
            if (code.length !== 6) {
                errorDiv.textContent = 'Please enter a 6-digit code';
                errorDiv.style.display = 'block';
                return;
            }
            
            try {
                loadingDiv.style.display = 'block';
                submitBtn.disabled = true;
                
                const response = await fetch('backend/verify-reset-code.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        code: code
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    successDiv.textContent = 'Code verified! Redirecting...';
                    successDiv.style.display = 'block';
                    setTimeout(() => {
                        window.location.href = 'reset-password.php';
                    }, 1500);
                } else {
                    errorDiv.textContent = data.message || 'Invalid or expired code';
                    errorDiv.style.display = 'block';
                    submitBtn.disabled = false;
                }
            } catch (error) {
                errorDiv.textContent = 'An error occurred. Please try again.';
                errorDiv.style.display = 'block';
                submitBtn.disabled = false;
            } finally {
                loadingDiv.style.display = 'none';
            }
        });
    </script>
</body>
</html>
