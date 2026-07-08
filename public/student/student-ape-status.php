<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>APE Status Tracker - CLINiQ Student Portal</title>
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
                @apply inline-flex items-center justify-center px-4 py-2 text-xs font-bold rounded-xl border border-transparent shadow-sm text-white bg-primary hover:bg-primary-container focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-colors cursor-pointer;
            }
            .cc-button-secondary {
                @apply inline-flex items-center justify-center px-4 py-2 text-xs font-bold rounded-xl border border-outline-variant/50 text-slate-700 bg-white hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-colors cursor-pointer;
            }
            .cc-card {
                @apply bg-white rounded-[2rem] p-6 border border-outline-variant/20 shadow-sm;
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
                <a href="student-dashboard.php" class="text-xs font-black transition-colors py-1 text-decoration-none text-slate-500 hover:text-slate-800">Dashboard</a>
                <a href="student-ape-status.php" class="text-xs font-black transition-colors py-1 text-decoration-none text-primary border-b-2 border-primary">APE Status</a>
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

    <main class="flex-1 p-4 md:p-6 w-full max-w-3xl mx-auto space-y-6">
        
        <!-- Student Info Header -->
        <div class="flex items-center gap-4 mb-2">
            <div class="w-16 h-16 rounded-full bg-slate-200 border-4 border-white shadow-sm flex items-center justify-center overflow-hidden shrink-0">
                <span class="material-symbols-outlined text-4xl text-slate-400">person</span>
            </div>
            <div>
                <h2 class="font-headline font-extrabold text-2xl text-brand-dark leading-tight">Juan dela Cruz</h2>
                <div class="text-sm font-bold text-slate-500 mt-0.5 flex flex-wrap items-center gap-x-2">
                    <span>Student ID: 23-00456</span>
                    <span class="text-slate-300">•</span>
                    <span>BSIT - 3rd Year</span>
                </div>
            </div>
        </div>

        <!-- APE Status Overview -->
        <div class="cc-card bg-amber-50/50 border-amber-200/50 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <h3 class="font-headline text-lg font-extrabold text-brand-dark">Annual Physical Examination (APE)</h3>
                <p class="text-xs font-bold text-slate-500 mt-1">Please complete all required documents to clear your APE status.</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <span class="text-xs font-black text-slate-400 uppercase tracking-wider">Overall Status:</span>
                <span class="cc-badge cc-badge-warning text-sm px-3 py-1">Pending</span>
            </div>
        </div>

        <!-- Hidden file input for simulated uploads -->
        <input type="file" id="file-picker" accept=".pdf, .png, .jpg, .jpeg" class="hidden" onchange="handleFileSelected(this)">

        <!-- Document Checklist -->
        <div class="space-y-4">
            <h4 class="font-headline text-base font-extrabold text-brand-dark uppercase tracking-wider text-slate-400">Required Documents</h4>

            <!-- Chest X-ray -->
            <div class="cc-card flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-5">
                <div class="space-y-1">
                    <h5 class="text-sm font-bold text-slate-800">Chest X-ray</h5>
                    <div class="flex items-center gap-2">
                        <span class="cc-badge cc-badge-success">Submitted</span>
                    </div>
                </div>
                <button class="cc-button-secondary shrink-0" onclick="triggerUpload('Chest X-ray')">
                    <span class="material-symbols-outlined text-[16px] mr-1.5">upload</span> Re-upload
                </button>
            </div>

            <!-- Fecalysis -->
            <div class="cc-card flex flex-col gap-3 p-5">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="space-y-1">
                        <h5 class="text-sm font-bold text-slate-800">Fecalysis</h5>
                        <div class="flex items-center gap-2">
                            <span class="cc-badge cc-badge-warning">Pending Review</span>
                        </div>
                    </div>
                    <button class="cc-button shrink-0" onclick="triggerUpload('Fecalysis')">
                        <span class="material-symbols-outlined text-[16px] mr-1.5">upload</span> Upload Document
                    </button>
                </div>
                <!-- Clinic feedback note -->
                <div class="bg-red-50 border border-red-100 rounded-xl p-3 flex items-start gap-2.5">
                    <span class="material-symbols-outlined text-[18px] text-red-600 shrink-0 mt-0.5">info</span>
                    <div class="text-xs font-bold text-red-800 leading-normal">
                        <strong>Clinic Note:</strong> Please resubmit — file is unreadable. Ensure the document is clear and complete.
                    </div>
                </div>
            </div>

            <!-- Urinalysis -->
            <div class="cc-card flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-5">
                <div class="space-y-1">
                    <h5 class="text-sm font-bold text-slate-800">Urinalysis</h5>
                    <div class="flex items-center gap-2">
                        <span class="cc-badge cc-badge-danger">Missing</span>
                    </div>
                </div>
                <button class="cc-button shrink-0" onclick="triggerUpload('Urinalysis')">
                    <span class="material-symbols-outlined text-[16px] mr-1.5">upload</span> Upload Document
                </button>
            </div>

            <!-- Dental Certificate -->
            <div class="cc-card flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-5">
                <div class="space-y-1">
                    <h5 class="text-sm font-bold text-slate-800">Dental Certificate</h5>
                    <div class="flex items-center gap-2">
                        <span class="cc-badge cc-badge-danger">Missing</span>
                    </div>
                </div>
                <button class="cc-button shrink-0" onclick="triggerUpload('Dental Certificate')">
                    <span class="material-symbols-outlined text-[16px] mr-1.5">upload</span> Upload Document
                </button>
            </div>

            <!-- Blood Test Results -->
            <div class="cc-card flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-5">
                <div class="space-y-1">
                    <h5 class="text-sm font-bold text-slate-800">Blood Test Results</h5>
                    <div class="flex items-center gap-2">
                        <span class="cc-badge cc-badge-warning">Pending Review</span>
                    </div>
                </div>
                <button class="cc-button-secondary shrink-0" onclick="triggerUpload('Blood Test Results')">
                    <span class="material-symbols-outlined text-[16px] mr-1.5">upload</span> Re-upload
                </button>
            </div>

            <!-- Drug Test Results -->
            <div class="cc-card flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-5">
                <div class="space-y-1">
                    <h5 class="text-sm font-bold text-slate-800">Drug Test Results</h5>
                    <div class="flex items-center gap-2">
                        <span class="cc-badge cc-badge-danger">Missing</span>
                    </div>
                </div>
                <button class="cc-button shrink-0" onclick="triggerUpload('Drug Test Results')">
                    <span class="material-symbols-outlined text-[16px] mr-1.5">upload</span> Upload Document
                </button>
            </div>

        </div>
    </main>

    <script>
        // Check if logged in
        if (localStorage.getItem('student_logged_in') !== 'true') {
            window.location.href = "student-login.php";
        }

        let activeDocName = "";

        function triggerUpload(docName) {
            activeDocName = docName;
            document.getElementById('file-picker').click();
        }

        function handleFileSelected(input) {
            if (input.files && input.files.length > 0) {
                const file = input.files[0];
                alert(`Successfully simulated upload of "${file.name}" for ${activeDocName}.\n\n(No backend upload logic is active in this demo version.)`);
                input.value = "";
            }
        }
    </script>
</body>
</html>
