<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - CLINiQ Student Portal</title>
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
                @apply inline-flex items-center justify-center px-4 py-3 border border-transparent text-sm font-bold rounded-2xl shadow-sm text-white bg-primary hover:bg-primary-container focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-colors w-full cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed;
            }
            .cc-input, .cc-select {
                @apply w-full min-h-[3rem] border border-slate-200 rounded-2xl bg-slate-50 text-slate-800 font-bold text-sm px-4 focus:outline-none focus:border-primary/40 focus:ring-2 focus:ring-primary/10 transition-all;
            }
            .cc-field {
                @apply space-y-1.5 mb-4;
            }
            .cc-label {
                @apply block text-xs font-black text-slate-400 uppercase tracking-wider;
            }
            .cc-card {
                @apply bg-white rounded-[2rem] p-6 sm:p-8 border border-outline-variant/20 shadow-sm;
            }
        }
        body { font-family: 'Inter', sans-serif; }
        h1, h2, h3, h4, h5, h6, .font-headline { font-family: 'Manrope', sans-serif; }
    </style>
</head>
<body class="bg-surface text-on-surface min-h-screen flex flex-col justify-between py-8">

    <main class="w-full max-w-lg mx-auto px-4 flex-1">

        <!-- Logo / Brand Header -->
        <div class="text-center mb-7">
            <div class="inline-flex items-center gap-3 mb-3">
                <span class="w-10 h-10 bg-primary text-white rounded-xl flex items-center justify-center shadow-lg shadow-primary/20">
                    <span class="material-symbols-outlined text-[22px]">clinical_notes</span>
                </span>
                <span class="font-headline font-extrabold text-2xl text-[#1c2a59]">PLP Clinic<span class="text-[#004d9c]">Connect</span></span>
            </div>
            <p class="text-xs font-bold text-slate-500 leading-relaxed max-w-xs mx-auto">
                Create your CLINiQ account to manage your APE submissions and clinic appointments.
            </p>
        </div>

        <!-- Registration Card -->
        <div class="cc-card">

            <!-- Success state (hidden by default) -->
            <div id="success-state" class="hidden text-center py-6">
                <div class="w-16 h-16 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto mb-4">
                    <span class="material-symbols-outlined text-4xl">check_circle</span>
                </div>
                <h3 class="font-headline text-xl font-extrabold text-brand-dark mb-2">Account Created!</h3>
                <p class="text-sm font-bold text-slate-500 mb-6">Your CLINiQ account has been created successfully. You can now log in to access the student portal.</p>
                <a href="student-login.php" class="cc-button">Go to Login</a>
            </div>

            <!-- Registration Form -->
            <form id="register-form" onsubmit="handleRegister(event)">

                <!-- Row: Student Number -->
                <div class="cc-field">
                    <label class="cc-label" for="student-number">Student Number</label>
                    <input type="text" id="student-number" class="cc-input" placeholder="e.g. 23-00456" required>
                </div>

                <!-- Row: Last Name & First Name -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    <div class="cc-field mb-0">
                        <label class="cc-label" for="last-name">Last Name</label>
                        <input type="text" id="last-name" class="cc-input" placeholder="e.g. Dela Cruz" required>
                    </div>
                    <div class="cc-field mb-0">
                        <label class="cc-label" for="first-name">First Name</label>
                        <input type="text" id="first-name" class="cc-input" placeholder="e.g. Juan" required>
                    </div>
                </div>

                <!-- Middle Name -->
                <div class="cc-field">
                    <label class="cc-label" for="middle-name">
                        Middle Name <span class="normal-case text-slate-300 font-bold">(Optional)</span>
                    </label>
                    <input type="text" id="middle-name" class="cc-input" placeholder="e.g. Santos">
                </div>

                <!-- Personal Email -->
                <div class="cc-field">
                    <label class="cc-label" for="personal-email">Personal Email</label>
                    <input type="email" id="personal-email" class="cc-input" placeholder="e.g. juan@gmail.com" required>
                </div>

                <!-- Row: Course & Year Level -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                    <div class="cc-field mb-0">
                        <label class="cc-label" for="course">Course</label>
                        <select id="course" class="cc-select" required>
                            <option value="" disabled selected>Select course</option>
                            <option value="BSIT">BSIT</option>
                            <option value="BSCS">BSCS</option>
                            <option value="BSIS">BSIS</option>
                            <option value="BSCpE">BSCpE</option>
                        </select>
                    </div>
                    <div class="cc-field mb-0">
                        <label class="cc-label" for="year-level">Year Level</label>
                        <select id="year-level" class="cc-select" required>
                            <option value="" disabled selected>Select year</option>
                            <option value="1st Year">1st Year</option>
                            <option value="2nd Year">2nd Year</option>
                            <option value="3rd Year">3rd Year</option>
                            <option value="4th Year">4th Year</option>
                        </select>
                    </div>
                </div>

                <!-- Section -->
                <div class="cc-field">
                    <label class="cc-label" for="section">
                        Section <span class="normal-case text-slate-300 font-bold">(Optional)</span>
                    </label>
                    <input type="text" id="section" class="cc-input" placeholder="e.g. 3-IT-A">
                </div>

                <!-- Password -->
                <div class="cc-field">
                    <label class="cc-label" for="password">Password</label>
                    <input type="password" id="password" class="cc-input" placeholder="Create a password" oninput="checkStrength(this.value)" required>
                    <!-- Password Strength Indicator -->
                    <div class="mt-2 space-y-1.5" id="strength-wrapper">
                        <div class="flex gap-1.5">
                            <div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden"><div id="bar1" class="h-full w-0 rounded-full transition-all duration-300"></div></div>
                            <div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden"><div id="bar2" class="h-full w-0 rounded-full transition-all duration-300"></div></div>
                            <div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden"><div id="bar3" class="h-full w-0 rounded-full transition-all duration-300"></div></div>
                        </div>
                        <p id="strength-label" class="text-xs font-bold text-slate-400"></p>
                    </div>
                </div>

                <!-- Confirm Password -->
                <div class="cc-field">
                    <label class="cc-label" for="confirm-password">Confirm Password</label>
                    <input type="password" id="confirm-password" class="cc-input" placeholder="Re-enter your password" required>
                    <p id="pw-match-msg" class="text-xs font-bold hidden"></p>
                </div>

                <!-- Confirmation Checkbox -->
                <div class="mb-6 mt-2">
                    <label class="flex items-start gap-3 cursor-pointer group">
                        <input type="checkbox" id="accuracy-confirm" class="mt-0.5 w-4 h-4 rounded accent-primary cursor-pointer" required>
                        <span class="text-xs font-bold text-slate-600 leading-relaxed group-hover:text-slate-800 transition-colors">
                            I confirm that the information I provided is accurate and truthful.
                        </span>
                    </label>
                </div>

                <!-- Error Alert -->
                <div id="error-alert" class="hidden mb-4 p-4 bg-red-50 border border-red-200 rounded-2xl flex items-start gap-2.5">
                    <span class="material-symbols-outlined text-[18px] text-red-600 shrink-0 mt-0.5">error</span>
                    <div class="text-xs font-bold text-red-800 leading-normal" id="error-msg">Please fix the errors above.</div>
                </div>

                <button type="submit" class="cc-button">
                    <span class="material-symbols-outlined text-[18px] mr-2">person_add</span>
                    Register
                </button>
            </form>
        </div>

        <!-- Back to Login -->
        <p class="text-center text-xs font-bold text-slate-500 mt-5">
            Already have an account?
            <a href="student-login.php" class="text-primary hover:underline font-extrabold">Login here.</a>
        </p>

    </main>

    <footer class="text-center py-6 text-[10px] font-bold text-slate-400 uppercase tracking-wider shrink-0">
        © 2026 CLINiQ. All rights reserved.
    </footer>

    <script>
        const strengthColors = {
            weak:   { bar: 'bg-red-400',    label: 'Weak',   color: 'text-red-500',   count: 1 },
            fair:   { bar: 'bg-amber-400',  label: 'Fair',   color: 'text-amber-600', count: 2 },
            strong: { bar: 'bg-emerald-500',label: 'Strong', color: 'text-emerald-600', count: 3 },
        };

        function checkStrength(value) {
            const bars = [document.getElementById('bar1'), document.getElementById('bar2'), document.getElementById('bar3')];
            const label = document.getElementById('strength-label');

            let level = null;
            if (value.length === 0) {
                bars.forEach(b => { b.className = 'h-full rounded-full transition-all duration-300'; b.style.width = '0'; });
                label.textContent = '';
                return;
            } else if (value.length < 6 || !/[A-Z]/.test(value) && !/[0-9]/.test(value)) {
                level = strengthColors.weak;
            } else if (value.length < 10 || !(/[A-Z]/.test(value) && /[0-9]/.test(value))) {
                level = strengthColors.fair;
            } else {
                level = strengthColors.strong;
            }

            bars.forEach((b, i) => {
                b.className = `h-full rounded-full transition-all duration-300 ${i < level.count ? level.bar : ''}`;
                b.style.width = i < level.count ? '100%' : '0';
            });
            label.textContent = `Password strength: ${level.label}`;
            label.className = `text-xs font-bold ${level.color}`;
        }

        document.getElementById('confirm-password').addEventListener('input', function() {
            const pw = document.getElementById('password').value;
            const msg = document.getElementById('pw-match-msg');
            if (!this.value) { msg.classList.add('hidden'); return; }
            msg.classList.remove('hidden');
            if (this.value === pw) {
                msg.textContent = '✓ Passwords match';
                msg.className = 'text-xs font-bold text-emerald-600';
            } else {
                msg.textContent = '✗ Passwords do not match';
                msg.className = 'text-xs font-bold text-red-500';
            }
        });

        function handleRegister(event) {
            event.preventDefault();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm-password').value;
            const errorAlert = document.getElementById('error-alert');
            const errorMsg = document.getElementById('error-msg');

            if (password !== confirmPassword) {
                errorAlert.classList.remove('hidden');
                errorMsg.textContent = 'Passwords do not match. Please re-enter your password.';
                return;
            }
            if (password.length < 6) {
                errorAlert.classList.remove('hidden');
                errorMsg.textContent = 'Password must be at least 6 characters long.';
                return;
            }

            errorAlert.classList.add('hidden');
            // Show success state
            document.getElementById('register-form').classList.add('hidden');
            document.getElementById('success-state').classList.remove('hidden');
        }
    </script>
</body>
</html>
