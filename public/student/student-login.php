<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Login - CLINiQ</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Manrope:wght@700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#00478d',
                        'primary-fixed': '#d6e3ff',
                        'primary-container': '#005eb8',
                        'on-primary': '#ffffff',
                        surface: '#f8f9fa',
                        'on-surface': '#191c1d',
                        'surface-container-low': '#f3f4f5',
                        'outline-variant': '#c2c6d4',
                        brand: '#00478d',
                        'brand-dark': '#1c2a59'
                    },
                    fontFamily: {
                        headline: ['Manrope', 'sans-serif'],
                        body: ['Inter', 'sans-serif']
                    }
                }
            }
        };
    </script>
    <style type="text/tailwindcss">
        @layer components {
            .cc-button {
                @apply inline-flex items-center justify-center px-4 py-3 border border-transparent text-sm font-bold rounded-2xl shadow-sm text-white bg-primary hover:bg-primary-container focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-colors w-full cursor-pointer;
            }
            .cc-input {
                @apply w-full min-h-[3rem] border border-slate-200 rounded-2xl bg-slate-50 text-slate-800 font-bold text-sm px-4 focus:outline-none focus:border-primary/30 focus:ring focus:ring-primary/15 transition-all;
            }
            .cc-field {
                @apply space-y-2 mb-5;
            }
            .cc-label {
                @apply block text-xs font-black text-slate-400 uppercase tracking-wider mb-1.5;
            }
            .cc-card {
                @apply bg-white rounded-[2rem] p-6 sm:p-8 border border-outline-variant/20 shadow-sm;
            }
        }
        
        body { font-family: 'Inter', sans-serif; }
        h1, h2, h3, h4, h5, h6, .font-headline { font-family: 'Manrope', sans-serif; }
    </style>
</head>
<body class="bg-surface text-on-surface min-h-screen flex flex-col justify-between">

    <!-- Top Spacer for alignment -->
    <div></div>

    <!-- Login Container -->
    <main class="w-full max-w-md mx-auto p-4">
        
        <!-- Logo / Brand Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center gap-3 mb-3">
                <span class="w-10 h-10 bg-primary text-white rounded-xl flex items-center justify-center shadow-lg shadow-primary/20">
                    <span class="material-symbols-outlined text-[22px]">clinical_notes</span>
                </span>
                <span class="font-headline font-extrabold text-2xl text-[#1c2a59]">PLP Clinic<span class="text-[#004d9c]">Connect</span></span>
            </div>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Student Portal Login</p>
        </div>

        <!-- Login Card -->
        <div class="cc-card">
            
            <!-- Error Alert (Hidden by default) -->
            <div id="error-alert" class="hidden mb-5 p-4 bg-red-50 border border-red-200 rounded-2xl flex items-start gap-2.5">
                <span class="material-symbols-outlined text-[18px] text-red-600 shrink-0 mt-0.5">error</span>
                <div class="text-xs font-bold text-red-800 leading-normal" id="error-msg">
                    Invalid Student ID or password. Please try again.
                </div>
            </div>

            <form onsubmit="handleLogin(event)">
                <!-- Student ID -->
                <div class="cc-field">
                    <label class="cc-label" for="student-id">Student ID</label>
                    <input type="text" id="student-id" class="cc-input" placeholder="Enter your Student ID (e.g. 23-00456)" required>
                </div>

                <!-- Password -->
                <div class="cc-field">
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="cc-label" for="password">Password</label>
                        <a href="student-forgot-password.php" class="text-[10px] font-black text-primary hover:underline uppercase tracking-wider">Forgot Password?</a>
                    </div>
                    <input type="password" id="password" class="cc-input" placeholder="••••••••" required>
                </div>

                <div class="mt-6">
                    <button type="submit" class="cc-button">
                        Login
                    </button>
                </div>

                <p class="text-center text-xs font-bold text-slate-500 mt-5 pt-5 border-t border-slate-100">
                    Don't have an account?
                    <a href="student-register.php" class="text-primary hover:underline font-extrabold">Register here.</a>
                </p>
            </form>
        </div>

        <!-- Small institutional notice -->
        <p class="text-center text-[11px] font-bold text-slate-400 mt-6 leading-relaxed px-4">
            Access is limited to enrolled students of Pamantasan ng Lungsod ng Pasig
        </p>

    </main>

    <!-- Footer Notice -->
    <footer class="text-center py-6 text-[10px] font-bold text-slate-400 uppercase tracking-wider shrink-0">
        © 2026 CLINiQ. All rights reserved.
    </footer>

    <script>
        function handleLogin(event) {
            event.preventDefault();
            const studentId = document.getElementById('student-id').value.trim();
            const password = document.getElementById('password').value;
            const errorAlert = document.getElementById('error-alert');
            const errorMsg = document.getElementById('error-msg');

            // Simulated authentication
            if (studentId === "23-00456" && password === "student123") {
                errorAlert.classList.add('hidden');
                // Store student details in localStorage to simulate session state
                localStorage.setItem('student_logged_in', 'true');
                localStorage.setItem('student_name', 'Juan dela Cruz');
                localStorage.setItem('student_id', '23-00456');
                localStorage.setItem('student_course', 'BSIT - 3rd Year');
                
                // Redirect to Dashboard page
                window.location.href = "student-dashboard.php";
            } else {
                errorAlert.classList.remove('hidden');
                if (studentId !== "23-00456") {
                    errorMsg.textContent = "Student ID not found. Use 23-00456 for the demo.";
                } else {
                    errorMsg.textContent = "Incorrect password. Use student123 for the demo.";
                }
            }
        }
    </script>
</body>
</html>
