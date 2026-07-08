<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - CLINiQ Student Portal</title>
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
            .cc-badge {
                @apply inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold uppercase tracking-wider;
            }
            .cc-badge-warning {
                @apply bg-amber-100 text-amber-800;
            }
            .cc-badge-success {
                @apply bg-emerald-100 text-emerald-800;
            }
            .cc-badge-danger {
                @apply bg-red-100 text-red-800;
            }
            .cc-button {
                @apply inline-flex items-center justify-center px-4 py-2.5 text-xs font-bold rounded-xl border border-transparent shadow-sm text-white bg-primary hover:bg-primary-container focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-colors cursor-pointer;
            }
            .cc-button-secondary {
                @apply inline-flex items-center justify-center px-4 py-2.5 text-xs font-bold rounded-xl border border-outline-variant/50 text-slate-700 bg-white hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-colors cursor-pointer;
            }
            .cc-card {
                @apply bg-white rounded-[2rem] p-6 border border-outline-variant/20 shadow-sm transition-all hover:shadow-md;
            }
        }
        
        body { font-family: 'Inter', sans-serif; }
        h1, h2, h3, h4, h5, h6, .font-headline { font-family: 'Manrope', sans-serif; }
    </style>
</head>
<body class="bg-surface text-on-surface min-h-screen flex flex-col">

    <!-- Top Navigation Bar -->
    <header class="w-full px-4 md:px-8 py-4 shrink-0 flex flex-col md:flex-row justify-between gap-4 md:items-center bg-white border-b border-outline-variant/20 shadow-sm relative z-20">
        <div class="flex items-center justify-between">
            <a href="student-dashboard.php" class="flex items-center gap-2.5 text-decoration-none">
                <span class="w-8 h-8 bg-primary text-white rounded-lg flex items-center justify-center shadow-lg shadow-primary/20">
                    <span class="material-symbols-outlined text-[18px]">clinical_notes</span>
                </span>
                <span class="font-headline font-extrabold text-base text-[#1c2a59]">PLP Clinic<span class="text-[#004d9c]">Connect</span></span>
            </a>
        </div>
        
        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-4 md:gap-8 w-full md:w-auto">
            <!-- Nav Links -->
            <nav class="flex items-center gap-5">
                <a href="student-dashboard.php" class="text-xs font-black transition-colors py-1 text-decoration-none text-primary border-b-2 border-primary">Dashboard</a>
                <a href="student-ape-status.php" class="text-xs font-black transition-colors py-1 text-decoration-none text-slate-500 hover:text-slate-800">APE Status</a>
                <a href="student-appointment.php" class="text-xs font-black transition-colors py-1 text-decoration-none text-slate-500 hover:text-slate-800">Book Appointment</a>
            </nav>
            
            <div class="flex items-center gap-4 justify-between sm:justify-start">
                <div class="text-right">
                    <div class="text-xs font-extrabold text-slate-800">Juan dela Cruz</div>
                    <div class="text-[10px] font-bold text-slate-400">ID: 23-00456</div>
                </div>
                <span class="w-px h-5 bg-slate-200"></span>
                <a href="student-login.php" onclick="localStorage.clear();" class="flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-red-600 transition-colors text-decoration-none">
                    Logout
                    <span class="material-symbols-outlined text-[16px]">logout</span>
                </a>
            </div>
        </div>
    </header>

    <main class="flex-1 p-4 md:p-6 w-full max-w-5xl mx-auto space-y-6">
        
        <!-- Welcome banner with a lively color gradient -->
        <div class="bg-gradient-to-r from-brand-dark to-primary text-white rounded-[2.5rem] p-6 sm:p-8 shadow-lg relative overflow-hidden flex flex-col md:flex-row md:items-center justify-between gap-6">
            <div class="relative z-10 space-y-2">
                <h2 class="font-headline font-black text-2xl sm:text-3xl">Welcome back, Juan!</h2>
                <p class="text-sm font-bold text-primary-fixed/80">Manage your physical examination records and book medical checkups with ease.</p>
            </div>
            <!-- Decorative circle -->
            <div class="absolute -right-16 -top-16 w-48 h-48 rounded-full bg-white/5 pointer-events-none"></div>
            <div class="absolute right-32 -bottom-24 w-64 h-64 rounded-full bg-white/5 pointer-events-none"></div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            
            <!-- Student Information Card -->
            <div class="cc-card md:col-span-1 flex flex-col justify-between">
                <div>
                    <div class="flex items-center gap-3 mb-6">
                        <span class="w-10 h-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center">
                            <span class="material-symbols-outlined">badge</span>
                        </span>
                        <h3 class="font-headline font-extrabold text-slate-800">Student Profile</h3>
                    </div>
                    <div class="space-y-4">
                        <div>
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Full Name</span>
                            <span class="text-sm font-bold text-slate-800">Juan dela Cruz</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Student ID</span>
                            <span class="text-sm font-bold text-slate-800">23-00456</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Course & Year</span>
                            <span class="text-sm font-bold text-slate-800">BSIT - 3rd Year</span>
                        </div>
                        <div>
                            <span class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Email Address</span>
                            <a href="mailto:juandelacruz@plpasig.edu.ph" class="text-sm font-bold text-primary hover:underline block">juandelacruz@plpasig.edu.ph</a>
                        </div>
                    </div>
                </div>
                <div class="pt-6 border-t border-slate-100 mt-6">
                    <span class="text-[10px] font-black text-emerald-600 flex items-center gap-1">
                        <span class="material-symbols-outlined text-[14px]">check_circle</span> Active Enrollment Status
                    </span>
                </div>
            </div>

            <!-- APE Completion Card -->
            <div class="cc-card md:col-span-1 flex flex-col justify-between">
                <div>
                    <div class="flex items-center gap-3 mb-6">
                        <span class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-700 flex items-center justify-center">
                            <span class="material-symbols-outlined">demography</span>
                        </span>
                        <h3 class="font-headline font-extrabold text-slate-800">APE Progress</h3>
                    </div>
                    
                    <!-- Progress visual (Circle or Bar) -->
                    <div class="space-y-5">
                        <div class="flex items-end justify-between">
                            <span class="text-xs font-bold text-slate-500">Document Completion</span>
                            <span class="font-headline text-2xl font-black text-slate-800">33%</span>
                        </div>
                        
                        <!-- Progress bar -->
                        <div class="w-full h-3 bg-slate-100 rounded-full overflow-hidden">
                            <div class="h-full bg-gradient-to-r from-amber-400 to-amber-600 rounded-full" style="width: 33.33%"></div>
                        </div>

                        <p class="text-xs font-bold text-slate-500">
                            2 of 6 documents submitted
                        </p>

                        <div class="bg-slate-50 rounded-xl p-3 border border-slate-100 flex justify-between items-center text-xs">
                            <span class="font-bold text-slate-400">Date Completed</span>
                            <span class="font-bold text-slate-600">Not yet completed</span>
                        </div>
                    </div>
                </div>

                <div class="mt-8">
                    <a href="student-ape-status.php" class="cc-button w-full bg-amber-600 hover:bg-amber-700 text-center flex items-center justify-center gap-1.5">
                        View APE Status
                        <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
                    </a>
                </div>
            </div>

            <!-- Pending Appointment Card -->
            <div class="cc-card md:col-span-1 flex flex-col justify-between">
                <div>
                    <div class="flex items-center gap-3 mb-6">
                        <span class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-700 flex items-center justify-center">
                            <span class="material-symbols-outlined">event_upcoming</span>
                        </span>
                        <h3 class="font-headline font-extrabold text-slate-800">Next Appointment</h3>
                    </div>

                    <div id="appointment-placeholder-content" class="space-y-4">
                        <!-- Pending Checkup info -->
                        <div class="p-4 bg-emerald-50/50 border border-emerald-100 rounded-2xl space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] font-black text-slate-400 uppercase tracking-wider block">Type</span>
                                <span class="cc-badge cc-badge-warning">Pending</span>
                            </div>
                            <div>
                                <h4 class="text-sm font-extrabold text-slate-800">General Checkup</h4>
                                <p class="text-xs font-bold text-slate-500 mt-1 flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[14px]">calendar_today</span>
                                    July 15, 2026 at 10:00 AM
                                </p>
                            </div>
                        </div>
                        <p class="text-[11px] font-bold text-slate-400 italic">
                            * Please arrive 10 minutes before your scheduled time.
                        </p>
                    </div>
                </div>

                <div class="mt-8">
                    <a href="student-appointment.php" class="cc-button-secondary w-full text-center flex items-center justify-center gap-1.5">
                        Manage Appointments
                        <span class="material-symbols-outlined text-[16px]">schedule</span>
                    </a>
                </div>
            </div>

        </div>

        <!-- Contact Us Section -->
        <section class="cc-card mt-8 bg-slate-50 border-slate-200/40">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-6">
                <div class="space-y-2 max-w-xl">
                    <h3 class="font-headline font-extrabold text-lg text-slate-800 flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">contact_support</span>
                        Contact Us
                    </h3>
                    <p class="text-xs font-bold text-slate-500">
                        For concerns and inquiries, feel free to reach out to the PLP Health Services clinic.
                    </p>
                </div>
                
                <div class="flex flex-col sm:flex-row gap-3 shrink-0">
                    <a href="mailto:health_services@plpasig.edu.ph" class="cc-button-secondary bg-white hover:bg-slate-100 flex items-center justify-center gap-2 text-xs font-bold px-5">
                        <span class="material-symbols-outlined text-[16px] text-primary">mail</span>
                        health_services@plpasig.edu.ph
                    </a>
                    <a href="https://www.facebook.com/PLPHealthServices" target="_blank" rel="noopener noreferrer" class="cc-button bg-[#1877f2] hover:bg-[#166fe5] flex items-center justify-center gap-2 text-xs font-bold px-5">
                        <span class="material-symbols-outlined text-[16px]">public</span>
                        Visit our Facebook Page
                    </a>
                </div>
            </div>
        </section>

    </main>

    <script>
        // Check if logged in
        if (localStorage.getItem('student_logged_in') !== 'true') {
            window.location.href = "student-login.php";
        }
    </script>
</body>
</html>
