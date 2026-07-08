<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - CLINiQ Student Portal</title>
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
                @apply inline-flex items-center justify-center px-4 py-3 border border-transparent text-sm font-bold rounded-2xl shadow-sm text-white bg-primary hover:bg-primary-container focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-colors w-full cursor-pointer;
            }
            .cc-input {
                @apply w-full min-h-[3rem] border border-slate-200 rounded-2xl bg-slate-50 text-slate-800 font-bold text-sm px-4 focus:outline-none focus:border-primary/40 focus:ring-2 focus:ring-primary/10 transition-all;
            }
            .cc-field {
                @apply space-y-1.5 mb-5;
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
<body class="bg-surface text-on-surface min-h-screen flex flex-col justify-between">

    <div></div>

    <main class="w-full max-w-md mx-auto px-4 flex-1 flex flex-col justify-center py-10">

        <!-- Logo / Brand Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center gap-3 mb-3">
                <span class="w-10 h-10 bg-primary text-white rounded-xl flex items-center justify-center shadow-lg shadow-primary/20">
                    <span class="material-symbols-outlined text-[22px]">clinical_notes</span>
                </span>
                <span class="font-headline font-extrabold text-2xl text-[#1c2a59]">PLP Clinic<span class="text-[#004d9c]">Connect</span></span>
            </div>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider">Password Recovery</p>
        </div>

        <!-- Card -->
        <div class="cc-card">

            <!-- Default state: the form -->
            <div id="form-state">
                <div class="mb-6">
                    <h2 class="font-headline font-extrabold text-xl text-brand-dark mb-2">Forgot your password?</h2>
                    <p class="text-xs font-bold text-slate-500 leading-relaxed">
                        Enter the personal email address linked to your CLINiQ account and we will send you a password reset link.
                    </p>
                </div>

                <form onsubmit="handleForgotPassword(event)">
                    <div class="cc-field">
                        <label class="cc-label" for="recovery-email">Personal Email</label>
                        <input type="email" id="recovery-email" class="cc-input" placeholder="e.g. juan@gmail.com" required>
                    </div>

                    <div class="mt-6">
                        <button type="submit" class="cc-button">
                            <span class="material-symbols-outlined text-[18px] mr-2">send</span>
                            Send Reset Link
                        </button>
                    </div>
                </form>
            </div>

            <!-- Success state (shown after submit) -->
            <div id="success-state" class="hidden text-center py-4">
                <div class="w-16 h-16 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto mb-5">
                    <span class="material-symbols-outlined text-4xl">mark_email_read</span>
                </div>
                <h3 class="font-headline text-xl font-extrabold text-brand-dark mb-3">Check Your Inbox</h3>
                <p class="text-sm font-bold text-slate-500 leading-relaxed">
                    If an account is associated with this email, a password reset link has been sent. Please check your inbox.
                </p>
            </div>

        </div>

        <!-- Back to Login -->
        <p class="text-center text-xs font-bold text-slate-500 mt-5">
            <a href="student-login.php" class="inline-flex items-center gap-1 text-primary hover:underline font-extrabold">
                <span class="material-symbols-outlined text-[14px]">arrow_back</span>
                Back to Login
            </a>
        </p>

    </main>

    <footer class="text-center py-6 text-[10px] font-bold text-slate-400 uppercase tracking-wider shrink-0">
        © 2026 CLINiQ. All rights reserved.
    </footer>

    <script>
        function handleForgotPassword(event) {
            event.preventDefault();
            document.getElementById('form-state').classList.add('hidden');
            document.getElementById('success-state').classList.remove('hidden');
        }
    </script>
</body>
</html>
