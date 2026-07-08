<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment Booking - CLINiQ Student Portal</title>
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
                @apply inline-flex items-center justify-center px-4 py-3 border border-transparent text-sm font-bold rounded-2xl shadow-sm text-white bg-primary hover:bg-primary-container focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-colors w-full cursor-pointer;
            }
            .cc-button-secondary {
                @apply inline-flex items-center justify-center px-4 py-3 border border-outline-variant/50 text-sm font-bold rounded-2xl shadow-sm text-slate-700 bg-white hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-colors w-full cursor-pointer;
            }
            .cc-input, .cc-select {
                @apply w-full min-h-[3rem] border border-slate-200 rounded-2xl bg-slate-50 text-slate-800 font-bold text-sm px-4 focus:outline-none focus:border-primary/30 focus:ring focus:ring-primary/15 transition-all;
            }
            .cc-field {
                @apply space-y-2 mb-5;
            }
            .cc-label {
                @apply block text-xs font-black text-slate-400 uppercase tracking-wider mb-1.5;
            }
            .cc-card {
                @apply bg-white rounded-[2rem] p-6 border border-outline-variant/20 shadow-sm;
            }
            .cc-modal {
                @apply fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm opacity-0 pointer-events-none transition-opacity duration-300;
            }
            .cc-modal.active {
                @apply opacity-100 pointer-events-auto;
            }
            .cc-modal-content {
                @apply bg-white rounded-[2.5rem] p-6 sm:p-8 w-full max-w-md shadow-2xl transform scale-95 transition-transform duration-300;
            }
            .cc-modal.active .cc-modal-content {
                @apply scale-100;
            }
        }
        
        body { font-family: 'Inter', sans-serif; }
        h1, h2, h3, h4, h5, h6, .font-headline { font-family: 'Manrope', sans-serif; }
        
        .time-slot {
            @apply px-4 py-3 border border-slate-200 rounded-2xl text-center text-sm font-bold text-slate-600 bg-white cursor-pointer transition-all hover:border-primary hover:text-primary;
        }
        .time-slot.selected {
            @apply border-primary bg-primary text-white shadow-md;
        }
        
        .date-btn {
            @apply w-10 h-10 flex items-center justify-center rounded-xl text-sm font-bold cursor-pointer transition-all;
        }
        .date-btn.available {
            @apply bg-primary/10 text-primary hover:bg-primary/20;
        }
        .date-btn.selected {
            @apply bg-primary text-white shadow-md;
        }
        .date-btn.disabled {
            @apply text-slate-300 cursor-not-allowed;
        }
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
                <a href="student-ape-status.php" class="text-xs font-black transition-colors py-1 text-decoration-none text-slate-500 hover:text-slate-800">APE Status</a>
                <a href="student-appointment.php" class="text-xs font-black transition-colors py-1 text-decoration-none text-primary border-b-2 border-primary">Book Appointment</a>
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

        <h3 class="font-headline text-xl font-extrabold text-brand-dark mt-8 mb-4">Book an Appointment</h3>

        <div class="cc-card">
            
            <form id="booking-form" onsubmit="return false;">
                
                <!-- Appointment Type -->
                <div class="cc-field">
                    <label class="cc-label">Appointment Type</label>
                    <select id="appt-type" class="cc-select cc-input">
                        <option value="" disabled selected>Select an appointment type...</option>
                        <option value="General Checkup">General Checkup</option>
                        <option value="Dental">Dental</option>
                        <option value="Medical Consultation">Medical Consultation</option>
                    </select>
                </div>

                <!-- Date Picker (Simulated) -->
                <div class="cc-field mt-6">
                    <label class="cc-label flex justify-between">
                        <span>Select Date</span>
                        <span class="text-primary font-bold lowercase normal-case">July 2026</span>
                    </label>
                    
                    <div class="grid grid-cols-7 gap-1 text-center mb-2">
                        <div class="text-[10px] font-black text-slate-400 uppercase">Su</div>
                        <div class="text-[10px] font-black text-slate-400 uppercase">Mo</div>
                        <div class="text-[10px] font-black text-slate-400 uppercase">Tu</div>
                        <div class="text-[10px] font-black text-slate-400 uppercase">We</div>
                        <div class="text-[10px] font-black text-slate-400 uppercase">Th</div>
                        <div class="text-[10px] font-black text-slate-400 uppercase">Fr</div>
                        <div class="text-[10px] font-black text-slate-400 uppercase">Sa</div>
                    </div>
                    
                    <div class="grid grid-cols-7 gap-1 place-items-center" id="calendar-grid">
                        <div class="date-btn disabled">28</div>
                        <div class="date-btn disabled">29</div>
                        <div class="date-btn disabled">30</div>
                        <div class="date-btn disabled">1</div>
                        <div class="date-btn disabled">2</div>
                        <div class="date-btn disabled">3</div>
                        <div class="date-btn disabled">4</div>
                        
                        <div class="date-btn disabled">5</div>
                        <div class="date-btn disabled">6</div>
                        <div class="date-btn disabled">7</div>
                        <div class="date-btn available" onclick="selectDate(this, 'July 8, 2026')">8</div>
                        <div class="date-btn available" onclick="selectDate(this, 'July 9, 2026')">9</div>
                        <div class="date-btn disabled">10</div>
                        <div class="date-btn disabled">11</div>
                        
                        <div class="date-btn disabled">12</div>
                        <div class="date-btn available" onclick="selectDate(this, 'July 13, 2026')">13</div>
                        <div class="date-btn available" onclick="selectDate(this, 'July 14, 2026')">14</div>
                        <div class="date-btn disabled">15</div>
                        <div class="date-btn available" onclick="selectDate(this, 'July 17, 2026')">17</div>
                        <div class="date-btn available" onclick="selectDate(this, 'July 18, 2026')">18</div>
                        <div class="date-btn disabled">19</div>
                    </div>
                </div>

                <!-- Time Slots -->
                <div class="cc-field mt-6">
                    <label class="cc-label">Available Time Slots</label>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3" id="time-slots">
                        <div class="time-slot" onclick="selectTime(this, '8:00 AM')">8:00 AM</div>
                        <div class="time-slot" onclick="selectTime(this, '9:00 AM')">9:00 AM</div>
                        <div class="time-slot" onclick="selectTime(this, '10:00 AM')">10:00 AM</div>
                        <div class="time-slot" onclick="selectTime(this, '1:00 PM')">1:00 PM</div>
                        <div class="time-slot" onclick="selectTime(this, '2:00 PM')">2:00 PM</div>
                    </div>
                </div>

                <div class="mt-8">
                    <button type="button" class="cc-button" onclick="showConfirmation()">
                        Book Appointment
                    </button>
                </div>
            </form>
        </div>
    </main>

    <!-- Confirmation Modal -->
    <div id="confirmation-modal" class="cc-modal">
        <div class="cc-modal-content">
            <div class="w-16 h-16 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto mb-4">
                <span class="material-symbols-outlined text-4xl">check_circle</span>
            </div>
            <h3 class="font-headline text-2xl font-extrabold text-center text-brand-dark mb-2">Confirm Booking</h3>
            <p class="text-sm text-center text-slate-500 font-medium mb-6">Please review your appointment details before confirming.</p>
            
            <div class="bg-slate-50 rounded-2xl p-5 border border-slate-100 space-y-4 mb-6">
                <div class="flex justify-between items-center pb-3 border-b border-slate-200">
                    <span class="text-xs font-bold text-slate-400 uppercase">Reference</span>
                    <span class="text-sm font-black text-brand-dark">APT-2026-00123</span>
                </div>
                <div class="flex justify-between items-center pb-3 border-b border-slate-200">
                    <span class="text-xs font-bold text-slate-400 uppercase">Type</span>
                    <span id="summary-type" class="text-sm font-bold text-slate-800">--</span>
                </div>
                <div class="flex justify-between items-center pb-3 border-b border-slate-200">
                    <span class="text-xs font-bold text-slate-400 uppercase">Date</span>
                    <span id="summary-date" class="text-sm font-bold text-slate-800">--</span>
                </div>
                <div class="flex justify-between items-center pb-3 border-b border-slate-200">
                    <span class="text-xs font-bold text-slate-400 uppercase">Time</span>
                    <span id="summary-time" class="text-sm font-bold text-slate-800">--</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-xs font-bold text-slate-400 uppercase">Status</span>
                    <span class="cc-badge cc-badge-warning">Pending</span>
                </div>
            </div>

            <div class="flex gap-3">
                <button class="cc-button-secondary" onclick="closeModal()">Cancel</button>
                <button class="cc-button" onclick="confirmBooking()">Confirm</button>
            </div>
        </div>
    </div>

    <script>
        // Check if logged in
        if (localStorage.getItem('student_logged_in') !== 'true') {
            window.location.href = "student-login.php";
        }

        let selectedDateStr = null;
        let selectedTimeStr = null;

        function selectDate(el, dateStr) {
            document.querySelectorAll('.date-btn.available').forEach(btn => btn.classList.remove('selected'));
            el.classList.add('selected');
            selectedDateStr = dateStr;
        }

        function selectTime(el, timeStr) {
            document.querySelectorAll('.time-slot').forEach(btn => btn.classList.remove('selected'));
            el.classList.add('selected');
            selectedTimeStr = timeStr;
        }

        function showConfirmation() {
            const type = document.getElementById('appt-type').value;
            
            if (!type || !selectedDateStr || !selectedTimeStr) {
                alert("Please select appointment type, date, and time slot.");
                return;
            }

            document.getElementById('summary-type').textContent = type;
            document.getElementById('summary-date').textContent = selectedDateStr;
            document.getElementById('summary-time').textContent = selectedTimeStr;

            document.getElementById('confirmation-modal').classList.add('active');
        }

        function closeModal() {
            document.getElementById('confirmation-modal').classList.remove('active');
        }

        function confirmBooking() {
            alert("Booking confirmed successfully! Redirecting...");
            closeModal();
            // Reset form
            document.getElementById('booking-form').reset();
            document.querySelectorAll('.date-btn.selected, .time-slot.selected').forEach(el => el.classList.remove('selected'));
            selectedDateStr = null;
            selectedTimeStr = null;
        }
    </script>
</body>
</html>
