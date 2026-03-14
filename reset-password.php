<?php
session_start();

// Check if user verified the code
if (!isset($_SESSION['reset_verified']) || $_SESSION['reset_verified'] !== true) {
    header("Location: forgotpassword.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - San Francisco System</title>
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
            max-width: 450px;
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
        input[type="password"],
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input[type="password"]:focus,
        input[type="text"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.1);
        }
        .password-strength {
            margin-top: 8px;
            padding: 10px;
            border-radius: 5px;
            font-size: 12px;
            display: none;
        }
        .strength-weak {
            background: #fadbd8;
            color: #c0392b;
        }
        .strength-fair {
            background: #fdebd0;
            color: #d68910;
        }
        .strength-good {
            background: #d5f4e6;
            color: #27ae60;
        }
        .requirements {
            margin-top: 10px;
            font-size: 12px;
            color: #666;
        }
        .requirement {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
            padding: 5px 0;
        }
        .requirement::before {
            content: "✗";
            display: inline-block;
            margin-right: 8px;
            color: #e74c3c;
            font-weight: bold;
            width: 20px;
        }
        .requirement.met::before {
            content: "✓";
            color: #27ae60;
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
            margin-top: 10px;
        }
        .btn:hover:not(:disabled) {
            transform: translateY(-2px);
        }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
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
        .back-link {
            text-align: center;
            margin-top: 20px;
        }
        .back-link a {
            color: #667eea;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }
        .back-link a:hover {
            text-decoration: underline;
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
        .toggle-password {
            position: relative;
        }
        .toggle-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #667eea;
            font-size: 14px;
            padding: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Reset Password</h1>
        <p class="subtitle">Enter your new password</p>
        
        <form id="resetForm">
            <div class="form-group">
                <label for="password">New Password</label>
                <div class="toggle-password">
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        placeholder="Enter new password"
                        required
                    >
                    <button type="button" class="toggle-btn" onclick="togglePassword('password')">Show</button>
                </div>
                <div class="password-strength" id="passwordStrength"></div>
                <div class="requirements">
                    <div class="requirement" id="req-length">Minimum 8 characters</div>
                    <div class="requirement" id="req-upper">At least one uppercase letter</div>
                    <div class="requirement" id="req-lower">At least one lowercase letter</div>
                    <div class="requirement" id="req-number">At least one number</div>
                    <div class="requirement" id="req-special">At least one special character (!@#$%^&*)</div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="toggle-password">
                    <input 
                        type="password" 
                        id="confirm_password" 
                        name="confirm_password" 
                        placeholder="Confirm new password"
                        required
                    >
                    <button type="button" class="toggle-btn" onclick="togglePassword('confirm_password')">Show</button>
                </div>
            </div>
            
            <div class="error-message" id="errorMessage"></div>
            <div class="success-message" id="successMessage"></div>
            
            <button type="submit" class="btn" id="submitBtn">Reset Password</button>
            <div class="loading" id="loading">
                <div class="spinner"></div>
            </div>
        </form>
        
        <div class="back-link">
            <a href="login.php">Back to Login</a>
        </div>
    </div>

    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const btn = event.target;
            if (field.type === 'password') {
                field.type = 'text';
                btn.textContent = 'Hide';
            } else {
                field.type = 'password';
                btn.textContent = 'Show';
            }
        }

        function checkPasswordStrength(password) {
            const requirements = {
                length: password.length >= 8,
                upper: /[A-Z]/.test(password),
                lower: /[a-z]/.test(password),
                number: /[0-9]/.test(password),
                special: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password)
            };

            // Update requirement indicators
            document.getElementById('req-length').classList.toggle('met', requirements.length);
            document.getElementById('req-upper').classList.toggle('met', requirements.upper);
            document.getElementById('req-lower').classList.toggle('met', requirements.lower);
            document.getElementById('req-number').classList.toggle('met', requirements.number);
            document.getElementById('req-special').classList.toggle('met', requirements.special);

            // Calculate strength
            const metRequirements = Object.values(requirements).filter(v => v).length;
            const strengthDiv = document.getElementById('passwordStrength');

            if (password === '') {
                strengthDiv.style.display = 'none';
            } else {
                strengthDiv.style.display = 'block';
                if (metRequirements < 3) {
                    strengthDiv.className = 'password-strength strength-weak';
                    strengthDiv.textContent = '🔴 Weak password';
                } else if (metRequirements < 5) {
                    strengthDiv.className = 'password-strength strength-fair';
                    strengthDiv.textContent = '🟡 Fair password';
                } else {
                    strengthDiv.className = 'password-strength strength-good';
                    strengthDiv.textContent = '🟢 Strong password';
                }
            }

            // Check if all requirements met
            return Object.values(requirements).every(v => v);
        }

        document.getElementById('password').addEventListener('input', function(e) {
            checkPasswordStrength(e.target.value);
        });

        document.getElementById('resetForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const errorDiv = document.getElementById('errorMessage');
            const successDiv = document.getElementById('successMessage');
            const submitBtn = document.getElementById('submitBtn');
            const loadingDiv = document.getElementById('loading');

            // Reset messages
            errorDiv.style.display = 'none';
            successDiv.style.display = 'none';

            // Validation
            if (!checkPasswordStrength(password)) {
                errorDiv.textContent = 'Password does not meet all requirements';
                errorDiv.style.display = 'block';
                return;
            }

            if (password !== confirmPassword) {
                errorDiv.textContent = 'Passwords do not match';
                errorDiv.style.display = 'block';
                return;
            }

            try {
                loadingDiv.style.display = 'block';
                submitBtn.disabled = true;

                const response = await fetch('backend/update-reset-password.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        password: password
                    })
                });

                const text = await response.text();
                console.log('Response text:', text);
                
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    errorDiv.textContent = 'Server error: Invalid response. Check browser console.';
                    errorDiv.style.display = 'block';
                    console.error('JSON Parse error:', e);
                    submitBtn.disabled = false;
                    loadingDiv.style.display = 'none';
                    return;
                }

                if (data.success) {
                    successDiv.textContent = 'Password reset successfully! Redirecting to login...';
                    successDiv.style.display = 'block';
                    setTimeout(() => {
                        window.location.href = 'password-reset-success.php';
                    }, 1500);
                } else {
                    errorDiv.textContent = data.message || 'Failed to reset password';
                    errorDiv.style.display = 'block';
                    submitBtn.disabled = false;
                }
            } catch (error) {
                console.error('Fetch error:', error);
                errorDiv.textContent = 'An error occurred: ' + error.message;
                errorDiv.style.display = 'block';
                submitBtn.disabled = false;
            } finally {
                loadingDiv.style.display = 'none';
            }
        });
    </script>
</body>
</html>
