# CLINiQ 15-Minute Presentation Plan and Script

## Basis of Review

This plan is based on the actual CLINiQ source code, database schema, seed data, services, public staff pages, student portal pages, and the rendered/extracted PowerPoint `D:\Downloads\CLINiQ_PPT (1).pptx`. The existing deck is visually clean and mostly usable, but it should be revised so it does not overclaim features that are only partially implemented.

Communication job: By the end, the panel should understand that CLINiQ is a working school clinic management prototype that centralizes records, visits, appointments, APE workflow, inventory, emergency passport links, and nurse alerts, while still having clear prototype limitations.

Recommended final length: 14 minutes 35 seconds.

Recommended presenters:
- Presenter 1: academic context and problem framing
- Presenter 2: system design, implemented features, and live demo
- Presenter 3: limitations, future direction, and conclusion

## Current PowerPoint Accuracy Audit

| Current Slide | Verdict | Keep / Revise / Remove | Notes |
|---|---|---|---|
| 1. Title | Accurate | Keep with minor cleanup | Has members, title, course, and institutional context. Fix encoding artifacts if visible in text exports and ensure member photos are clear. |
| 2. Introduction | Mostly accurate | Merge with Slide 3 or shorten | Correct purpose, but too general. Say it serves clinic staff and students, with visitor/self-logbook support. |
| 3. Background | Accurate | Keep as condensed problem slide | Good contrast between manual process and CLINiQ solution. Can be merged with Slide 2 if time is tight. |
| 4. Statement of the Problem | Mostly accurate | Keep but tighten | The ISO/IEC 25010 point is a study evaluation question, not an implemented module. Present it as evaluation criteria, not a system feature. |
| 5. Scope and Limitation | Needs revision | Keep | Scope is mostly right, but "Medical Document Upload" is only prototype-level. Student APE has file selection UI, while backend upload handling is not fully wired. Use "APE document tracking/digital keeping." |
| 6. Concept of the Study | Incomplete | Keep but revise | Current diagram is too abstract. Replace with Input -> Process -> Output, naming actual modules. |
| 7. Core Features | Mostly accurate | Revise | Add actual implemented features: student portal, appointments, APE work queues, inventory loans/restocking/archive, reports. Avoid saying full secure file upload unless implemented. |
| 8. Patient Risk Classification | Needs revision | Keep | Actual classifier uses symptoms, temperature, pulse, blood pressure, critical keywords, configurable thresholds. It does not directly score allergies/existing conditions. |
| 9. QR/NFC Emergency Health Passport | Partly inaccurate | Merge with Slide 10 | The implementation has tokenized student passport preview/live passport, and a public emergency report page. Do not say only authorized clinic personnel can access the emergency profile unless referring to full records. Say token validation protects access and full records remain behind login. |
| 10. Real-Time Nurse Alerting | Mostly accurate | Merge with Slide 9 | Use "near real-time" because the frontend uses lightweight polling, not websockets. Alerts can come from manual alerts, emergency passport incident reports, and high/critical visit escalation. |
| 11. Demo Overview | Redundant | Merge with Slide 12 | Keep one demo roadmap slide only. |
| 12. Demo Screens | Redundant | Remove/merge | Repeats Slide 11. Replace with one clear demo route. |
| 13. Conclusion | Accurate but generic | Keep and strengthen | Add honest prototype status: implemented working modules plus remaining enhancements. |

## Optimal 12-Slide Flow

### Slide 1 - Title and Presenters
Purpose: Establish project identity and presenters.
Suggested improvements: Keep current layout. Ensure all member names and pictures are readable. Use the exact title: "CLINiQ: A Smart Clinic Information Management System with QR/NFC Patient Health Passport and Real-Time Nurse Alerting."
Estimated time: 25 seconds.
Assigned presenter: Presenter 1.
Key points: Project title, group members, target institution, purpose of the presentation.
Transition: "Before showing the system, we will first explain the clinic problem it addresses."

### Slide 2 - Background of the Study
Purpose: Explain why the system is needed.
Suggested improvements: Merge current Slides 2 and 3 into a sharper problem-background slide.
Estimated time: 55 seconds.
Assigned presenter: Presenter 1.
Key points: Paper-based records delay retrieval, manual documentation can be incomplete, emergency information is hard to access quickly, clinic staff need better prioritization and coordination.
Transition: "From these issues, we defined the main research and system problem."

### Slide 3 - Statement of the Problem
Purpose: Present the general and specific problems.
Suggested improvements: Keep the five questions, but shorten visible text. Clarify ISO/IEC 25010 as an evaluation lens.
Estimated time: 55 seconds.
Assigned presenter: Presenter 1.
Key points: Improve EHR access/security, appointment workflow, risk prioritization, QR/NFC emergency support, and overall system quality.
Transition: "These problems also define the boundaries of what our prototype includes and excludes."

### Slide 4 - Scope and Limitations
Purpose: Set honest expectations.
Suggested improvements: Revise scope to "APE document tracking and digital keeping" instead of overclaiming complete upload storage. Keep limitations but add "prototype deployment" and "no diagnosis."
Estimated time: 50 seconds.
Assigned presenter: Presenter 1.
Key points: Includes EHR, visits, appointments, risk scoring, passport, alerts, APE workflow, inventory, referrals, reports, and student portal. Excludes telemedicine, hospital integration, automatic parent/hospital alerts, and diagnostic AI.
Transition: "With the scope clear, we can show how the concept connects users, data, and clinic outputs."

### Slide 5 - Conceptual Framework / System Flow
Purpose: Show how the system works at a high level.
Suggested improvements: Replace abstract boxes with Input -> CLINiQ processing modules -> Outputs.
Estimated time: 1 minute.
Assigned presenter: Presenter 1.
Key points: Inputs are student/staff details, symptoms, vitals, appointments, APE requirements, inventory, emergency reports. Processing modules include EHR, visit workflow, classifier, alerts, APE queues, inventory, and reports. Outputs are patient profile, risk level, nurse alert, appointment decision, APE status, stock warning, CSV report.
Transition: "Now that the concept is clear, we will move from study framework to actual implementation."

### Slide 6 - Implementation Snapshot
Purpose: Prove what has actually been built.
Suggested improvements: New slide replacing the broad Core Features slide or placed before it. Show staff side, student side, database/service layer.
Estimated time: 1 minute.
Assigned presenter: Presenter 2.
Key points: PHP 8, MySQL/MariaDB, XAMPP, Tailwind CSS, JavaScript fetch/AJAX, PDO prepared statements, modular folders. Staff side has dashboard, patients, visits, alerts, inventory, APE, appointments, referrals, reports, settings. Student side has dashboard, APE status, appointment request, health passport.
Transition: "The next two slides focus on the two features that make CLINiQ more than a basic record system."

### Slide 7 - Patient Risk Classification
Purpose: Explain the decision-support logic.
Suggested improvements: Correct inputs to symptoms, temperature, blood pressure, pulse rate, and configured critical keywords. Mention configurable thresholds.
Estimated time: 50 seconds.
Assigned presenter: Presenter 2.
Key points: Rule-based scoring, outputs Low/Moderate/High/Critical, high/critical can trigger nurse alert, not diagnostic AI, nurse still decides final care.
Transition: "Once a case is urgent, the system also needs a way to notify clinic staff quickly."

### Slide 8 - QR/NFC Passport and Nurse Alerting
Purpose: Merge emergency passport and alert workflow into one coherent emergency story.
Suggested improvements: Merge current Slides 9 and 10. Change "instant" to "near real-time." Change "authentication check" to "token validation."
Estimated time: 1 minute.
Assigned presenter: Presenter 2.
Key points: Student health passport stores emergency-approved details, QR/NFC link uses a token, live passport can show emergency information and submit an incident, public emergency page can notify the clinic while protecting full records, nurse alerts appear in the staff dashboard through polling.
Transition: "We will now show the exact system flow using the working prototype."

### Slide 9 - Demo Roadmap
Purpose: Prepare the panel for the live demo.
Suggested improvements: Merge current Slides 11 and 12. Use only the screens that prove the system value.
Estimated time: 25 seconds.
Assigned presenter: Presenter 2.
Key points: Student appointment request, staff dashboard approval, visit recording with risk classification, patient profile, passport incident alert, APE queue, inventory/report preview.
Transition: "We will start from the student side, then switch to the clinic staff side."

### Slide 10 - Live System Demonstration
Purpose: Show the implemented workflow, not just screenshots.
Suggested improvements: This can be a title/holding slide while screen recording begins.
Estimated time: 5 minutes 45 seconds.
Assigned presenter: Presenter 2.
Key points: Follow the demo sequence below. Narration should focus on workflow outcomes, not every button.
Transition: "After the demo, we will summarize what is complete and what remains as future improvement."

### Slide 11 - Current Limitations and Future Enhancements
Purpose: Be honest and defense-ready.
Suggested improvements: New slide or revised scope follow-up slide.
Estimated time: 55 seconds.
Assigned presenter: Presenter 3.
Key points: Prototype is local/single-school, full role-based access is only partially enforced, file upload workflow needs hardening, QR/NFC is represented by tokenized links and QR placeholder, no hospital/parent integration, no diagnostic AI. Future work: stronger RBAC, real QR generation/NFC writing, secure upload storage, SMS/email alerts, audit logs, deployment hardening.
Transition: "Despite those limitations, the current prototype already addresses the core clinic workflow."

### Slide 12 - Conclusion
Purpose: Close with the value of the project.
Suggested improvements: Keep current conclusion but add implementation proof.
Estimated time: 45 seconds.
Assigned presenter: Presenter 3.
Key points: CLINiQ centralizes clinic operations, supports faster record retrieval, gives decision support for prioritization, connects emergency reporting to nurse alerts, and provides a foundation for a more secure campus clinic workflow.
Transition: "That concludes our presentation. We are ready for questions."

## Presentation Schedule

| Segment | Presenter | Time |
|---|---:|---:|
| Slides 1-5: Introduction, problem, scope, concept | Presenter 1 | 4:05 |
| Slides 6-9: Implementation, risk, emergency workflow, demo roadmap | Presenter 2 | 3:15 |
| Slide 10: Live system demo | Presenter 2 | 5:45 |
| Slides 11-12: Limitations, future work, conclusion | Presenter 3 | 1:30 |
| Buffer for transition delays | All | 0:40 |
| Total |  | 14:35 |

## Pages to Demonstrate Live

Show these because they prove the most value:
- Student login and appointment request
- Staff dashboard
- Staff appointment approval
- Manual visit recording
- Visit detail with risk classification and action taken
- Patient profile
- Student health passport live preview / live passport
- Staff alerts page
- APE work queues
- Inventory or Reports, very briefly

Do not demonstrate these unless asked:
- Student registration and forgot password, because they do not prove the capstone's main innovation.
- Patient create form, because patient records are better shown through the profile and visit workflow.
- Full inventory add/edit/archive/return flow, because it consumes time.
- Full referral CRUD, because it is supporting functionality.
- Settings page, except if asked about configurable risk rules.
- CSV export download, unless the panel specifically asks about reports.

## System Demonstration Plan

Target duration: 5 minutes 45 seconds.

Preparation before recording:
- Start Apache and MySQL in XAMPP.
- Import `database/schema.sql` into database `cliniq`.
- Use seeded demo data. If the dashboard looks empty, run the dashboard seed script.
- Open two browser tabs before recording:
  - Staff: `http://localhost/CLINiQ/public/`
  - Student: `http://localhost/CLINiQ/student/student-login.php`
- Use browser zoom around 90 percent if the laptop screen is small.
- Use staff account `admin@cliniq.local` with password `password`.
- Use student account `26-01024` with password `student123`.
- Keep a simple visit scenario ready: "fever, dizziness, shortness of breath after activity" with temperature `39.1`, BP `145/92`, pulse `115`.

Exact navigation sequence:

1. Student appointment request
   - Navigate to Student Portal.
   - Login with `26-01024 / student123`.
   - Go to Appointments.
   - Select "Medical Consultation."
   - Choose the first available future date and an available time slot, for example 9:00 AM.
   - Add note: "Fever and dizziness after class."
   - Click Send Appointment Request.
   - Expected result: appointment appears in Recent Appointment Requests as Pending.
   - Talking point: "Students can request clinic appointments without calling or lining up first. The request remains pending until clinic staff approves it."
   - Time: 45 seconds.

2. Staff dashboard and appointment approval
   - Switch to staff tab.
   - Login with `admin@cliniq.local / password`.
   - Show dashboard metrics: visits today, low stock, appointment requests, APE clearance, APE to review.
   - Point to Active Emergency Alerts, Appointments, Live Visitor Log, and APE Action Queue.
   - Approve the pending appointment from the dashboard or open Appointments and click Approve.
   - Expected result: appointment status becomes Scheduled.
   - Talking point: "The staff dashboard acts as the clinic command center. It combines scheduling, emergency alerts, daily visits, and APE workload."
   - Time: 55 seconds.

3. Record a clinic visit and trigger risk classification
   - Go to Visits, then Record Visit.
   - Enter student ID `26-01024`.
   - Select purpose "Medical Consult."
   - Chief complaint: "Fever, dizziness, shortness of breath after activity."
   - Vitals: BP `145/92`, Temp `39.1`, Heart `115`.
   - Symptoms/Assessment: "High fever, dizziness, shortness of breath."
   - Diagnosis: "For nurse assessment."
   - Treatment: "Rest, hydration, monitor vital signs, notify guardian if symptoms persist."
   - Optional inventory: select Paracetamol 500mg, quantity 1.
   - Click Save Visit.
   - Expected result: a visit record opens with High or Critical risk, scoring reasons, status, and treatment details. If configured, a nurse alert is created for high/critical risk.
   - Talking point: "The system does not diagnose. It uses transparent rules to help prioritize patients and make urgent cases harder to miss."
   - Time: 1 minute 25 seconds.

4. Patient profile and EHR
   - From the visit or Patients page, open the patient profile.
   - Show Clinical Snapshot, Health Alerts, Care Timeline, APE Documents, and Referrals.
   - Expected result: patient history is centralized instead of being scattered across paper records.
   - Talking point: "This profile is the electronic health record view. Clinic staff can see identity, emergency details, visit history, APE records, and referrals in one place."
   - Time: 35 seconds.

5. Student health passport and incident alert
   - Switch to the student tab.
   - Go to Health Passport.
   - Show editable emergency details, QR/NFC preview, and live passport preview.
   - Click View Live Passport.
   - On the live passport page, submit an incident report with location "Gymnasium" and note "Student needs clinic assistance."
   - Expected result: the passport page confirms the report, and a nurse alert is inserted in the staff system.
   - Talking point: "The passport uses a tokenized link. Emergency-approved information is separated from full clinic records, and incident reports can notify clinic staff."
   - Time: 1 minute 5 seconds.

6. Staff nurse alert response
   - Return to staff tab.
   - Open Alerts.
   - Show the new pending alert.
   - Click Acknowledge or open the alert report.
   - Expected result: alert status changes to In Progress or details page shows the report.
   - Talking point: "Alerts can come from emergency reports, manual submissions, or high-risk visit escalation. Staff can acknowledge and document the response."
   - Time: 35 seconds.

7. APE, inventory, and reports quick proof
   - Open APE.
   - Show the work queues: Document Review, Digital Submission, Follow-up, Completed.
   - Open Inventory briefly and point to low stock/expiring/equipment tracking, or mention that dispensing from visits updates stock.
   - Open Reports briefly and show date range, risk distribution, common complaints, monthly trend, and CSV export button.
   - Expected result: panel sees the supporting clinic operations beyond the main emergency workflow.
   - Talking point: "Beyond emergency response, CLINiQ also supports day-to-day clinic operations: APE tracking, medicine/equipment inventory, and reports."
   - Time: 1 minute 5 seconds.

## Complete Presentation Script

### Presenter 1

Slide 1:
"Good day everyone. We are presenting CLINiQ, a Smart Clinic Information Management System with QR/NFC Patient Health Passport and Real-Time Nurse Alerting for the Clinic of Pamantasan ng Lungsod ng Pasig. I am [name], together with [name] and [name]. In this presentation, we will explain the problem, the system concept, the implemented features, and then demonstrate the working prototype."

Slide 2:
"The PLP clinic handles many types of student and campus health concerns, including consultations, medical records, APE requirements, emergency cases, and inventory. In a manual setup, clinic records can be difficult to retrieve quickly, especially when information is stored on paper or handled in separate logs. This affects documentation, follow-up, and emergency response. CLINiQ was developed to bring these workflows into one digital platform."

Slide 3:
"The general problem is that manual and fragmented clinic processes can cause delays, incomplete data, and slower coordination. Specifically, our study asks how the system can improve electronic health records, appointment management, patient prioritization, emergency preparedness through QR/NFC passport and nurse alerting, and overall system quality using ISO/IEC 25010 as an evaluation guide."

Slide 4:
"The scope of CLINiQ includes electronic health records, clinic visit records, appointment requests, risk classification, QR/NFC emergency health passport, nurse alerting, APE workflow tracking, inventory, referrals, and basic reports. The system is designed for a single-school clinic prototype. It does not provide telemedicine, hospital integration, automatic parent or hospital alerts, or diagnosis. The risk classification is only a decision-support tool."

Slide 5:
"Our conceptual framework starts with inputs such as student information, symptoms, vital signs, appointment requests, APE documents, inventory data, and emergency reports. These pass through CLINiQ modules such as the EHR, visit workflow, risk classifier, appointment module, APE queues, inventory tracking, and alert system. The outputs are centralized patient records, risk levels, appointment decisions, nurse alerts, APE status, stock warnings, and clinic reports."

Transition to Presenter 2:
"Now, [Presenter 2] will explain how these concepts were implemented in the actual system."

### Presenter 2

Slide 6:
"For implementation, CLINiQ is built as a PHP and MySQL web application designed for XAMPP local deployment. The system uses PDO prepared statements for database operations, Tailwind CSS for the interface, and JavaScript fetch/AJAX for smoother interactions and alert polling. The staff side includes dashboard, patients, visits, alerts, inventory, APE, appointments, referrals, reports, and settings. The student side includes dashboard, APE status, appointment requests, and health passport management."

Slide 7:
"One important feature is patient risk classification. When a clinic visit is recorded, CLINiQ checks the submitted symptoms and vitals such as temperature, pulse rate, and blood pressure. It also checks configured critical symptom keywords. The system calculates a score and labels the visit as Low, Moderate, High, or Critical. This is not diagnostic AI. It is a transparent rule-based guide so nurses can prioritize cases more consistently."

Slide 8:
"The second key feature is the QR/NFC emergency workflow. Students can maintain emergency-approved details in their health passport. The passport uses a tokenized link, which can be represented by a QR code or NFC tag. A live passport can show emergency details and can also submit an incident report. On the staff side, these reports appear as nurse alerts. The alerting is near real-time through lightweight polling, and full clinic records remain protected behind login."

Slide 9:
"For the live demo, we will show one complete workflow: a student appointment request, staff approval, visit recording with risk classification, patient profile review, health passport incident reporting, nurse alert response, and a quick look at APE, inventory, and reports."

Slide 10 demo narration:
"We start in the student portal using a seeded student account. The student selects an appointment purpose, chooses an available schedule, and submits the request. Notice that the appointment is still pending, because clinic approval is required."

"Now we switch to the staff side. After login, the dashboard shows the operational view of the clinic: visits today, low stock, appointment requests, APE clearance, pending alerts, appointment queue, visitor log, and APE action queue. We approve the student appointment, which changes it from a request into a scheduled clinic appointment."

"Next, we record a clinic visit. After entering the student ID, CLINiQ loads the patient details. We enter the chief complaint, symptoms, and vital signs. When we save, the system classifies the risk level based on the configured rules. The result shows the score and the reasons, so the nurse can understand why the case was marked urgent."

"Here is the patient profile. Instead of checking separate paper records, the clinic can view the student's clinical snapshot, emergency details, visit timeline, APE records, and referrals in one place."

"Now we return to the student portal and open the health passport. The student can maintain emergency-approved information such as blood type, allergies, conditions, instructions, and guardian contact. The system also shows a QR/NFC preview and a live passport link. From the live passport, an incident report can be submitted, for example from the gymnasium."

"Back on the staff side, that incident appears in the nurse alerts module. Staff can open it, acknowledge it, and document response actions. Finally, we quickly show APE work queues, inventory tracking, and reports to show that CLINiQ also supports daily clinic operations beyond emergency response."

Transition to Presenter 3:
"After seeing the working system, [Presenter 3] will discuss the current limitations, future improvements, and conclusion."

### Presenter 3

Slide 11:
"Although CLINiQ is already functional as a prototype, there are important limitations. It is currently designed for a local, single-school deployment. Full role-based access exists in the user data, but some pages still rely mainly on login access, so stricter role enforcement should be added. The APE document workflow tracks digital submissions and document paths, but secure backend upload handling should be strengthened. The QR/NFC workflow is represented through tokenized links and QR preview, and future versions can add production QR generation, NFC writing, SMS or email alerts, stronger audit logs, and deployment hardening."

Slide 12:
"In conclusion, CLINiQ addresses the major issues of manual clinic management by centralizing records, supporting appointment approval, guiding patient prioritization, connecting emergency passport reports to nurse alerts, and providing APE, inventory, referral, and reporting support. The system does not replace clinical judgment, but it helps clinic personnel retrieve information faster, coordinate better, and respond more efficiently. That concludes our presentation. Thank you, and we are ready for questions."

## Final Duration Check

| Part | Duration |
|---|---:|
| Slide presentation before demo | 7:20 |
| Live demo | 5:45 |
| Limitations and conclusion | 1:30 |
| Transition buffer | 0:40 |
| Total | 14:35 |

The presentation fits within the 15-minute maximum with about 25 seconds remaining.
