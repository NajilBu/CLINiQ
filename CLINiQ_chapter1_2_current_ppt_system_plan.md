# CLINiQ Chapter 1-2 Presentation Plan

## Main Strategy

This presentation should be paper-first and system-supported.

The goal is not to prove that every system feature is production-ready. The goal is to show that the Chapter 1 problems and Chapter 2 concepts logically lead to the CLINiQ system design, and that the current prototype is enough proof that the proposed solution is feasible.

Core message:

> CLINiQ is a research-based clinic management prototype designed to address PLP clinic issues in records, appointments, patient prioritization, emergency response, and clinic workflow coordination.

## What To Prioritize

Prioritize:
- Problem, background, and objectives
- Scope and limitation
- Conceptual framework
- Chapter 2 concepts and gap
- Short proof-of-concept demo connected to the objectives

Do not over-prioritize:
- Long module-by-module demo
- Every CRUD feature
- Deep inventory/referral walkthrough
- Claims that are not fully implemented

## Current PPT Assessment

| Current Slide | Status | Recommendation |
|---|---|---|
| 1. Title | Good | Keep. Make sure member photos/names are clear. |
| 2. Introduction | Useful but system-heavy | Keep, but present it as proposed system overview, not final production claim. |
| 3. Background | Strong | Keep. This is very important for Chapter 1. |
| 4. Statement of the Problem | Strong | Keep. Connect each question to a module. |
| 5. Scope and Limitation | Important | Keep, but revise wording: use "APE tracking/digital keeping" instead of overclaiming complete upload workflow. |
| 6. Concept of the Study | Needs stronger explanation | Keep, but explain it as Input -> Process -> Output. |
| 7. Core Features | Useful | Keep, but shorten. Tie each feature to a specific problem/objective. |
| 8. Patient Risk Classification | Good but needs precision | Explain it as rule-based decision support using symptoms/vitals/keywords, not diagnosis. |
| 9. QR/NFC Emergency Health Passport | Needs careful wording | Say tokenized QR/NFC-ready emergency access. Avoid saying full medical access is public. |
| 10. Real-Time Nurse Alerting | Good | Say near real-time alerting through polling. |
| 11. System Demo Overview | Redundant with Slide 12 | Merge with Slide 12. |
| 12. Demo Screens to Showcase | Redundant | Use as one concise demo roadmap slide. |
| 13. Conclusion | Good but generic | Strengthen by connecting back to Chapter 1 problems and Chapter 2 support. |

## Recommended Final Flow

Use 12 slides by merging Slides 11 and 12.

### Slide 1 - Title
Purpose: Introduce the study, group, and institution.
Time: 30 seconds.
Presenter: Presenter 1.
Key points:
- Study title
- Group members
- PLP clinic context
Transition:
"Before discussing the system, we first explain the clinic problem that motivated the study."

### Slide 2 - Introduction
Purpose: Give a high-level idea of CLINiQ.
Time: 45 seconds.
Presenter: Presenter 1.
Key points:
- Web-based clinic information management prototype
- Designed for PLP clinic workflows
- Supports records, appointments, emergency response, and clinic coordination
Transition:
"This idea came from specific problems observed in manual clinic processes."

### Slide 3 - Background of the Study
Purpose: Explain the current problem situation.
Time: 1 minute.
Presenter: Presenter 1.
Key points:
- Paper-heavy records
- Slow information retrieval
- Incomplete or fragmented documentation
- Difficulty prioritizing patients
- Emergency response delays
Transition:
"From this background, we formulated the study problem."

### Slide 4 - Statement of the Problem
Purpose: Present the research questions.
Time: 1 minute 20 seconds.
Presenter: Presenter 1.
Key points:
- EHR management, accessibility, and security
- Appointment workflow and scheduling
- Risk classification for prioritization
- QR/NFC passport and nurse alerting for emergencies
- ISO/IEC 25010 as quality evaluation guide
Transition:
"These questions define what our system should cover and what it should not claim to do."

### Slide 5 - Scope and Limitation
Purpose: Set the project boundaries.
Time: 1 minute 10 seconds.
Presenter: Presenter 1.
Key points:
- Scope: EHR, appointments, risk classification, passport, alerts, APE tracking, inventory, referrals, reports
- Limitations: single-school prototype, no telemedicine, no hospital integration, no automatic parent/hospital alerts, not diagnostic AI
- Mention that some features are prototype-level and will be improved in later development
Transition:
"After defining the scope, we organized the system through our conceptual framework."

### Slide 6 - Conceptual Framework
Purpose: Show how the study idea becomes a system.
Time: 1 minute 15 seconds.
Presenter: Presenter 2.
Key points:
- Inputs: patient data, visits, symptoms, vitals, appointments, APE data, emergency reports
- Process: CLINiQ modules
- Outputs: patient records, risk level, alerts, appointment status, APE status, reports
Transition:
"The framework is supported by related concepts and studies from Chapter 2."

### Slide 7 - Chapter 2 Concepts and Research Gap
Purpose: Add the missing Chapter 2 connection.
Time: 2 minutes.
Presenter: Presenter 2.
Suggested improvement:
Add this as a new slide or replace part of Core Features if the deck must stay short.
Key points:
- EHR supports centralized records
- Appointment systems support scheduling and reduced congestion
- QR/NFC supports fast emergency access
- Alert systems support faster incident communication
- Rule-based classification supports prioritization but not diagnosis
- Gap: existing concepts are often separate; CLINiQ integrates them for a school clinic context
Transition:
"Based on those concepts, CLINiQ was designed as an integrated system with these core features."

### Slide 8 - Core Features Mapped To Objectives
Purpose: Connect system modules to the paper.
Time: 1 minute 20 seconds.
Presenter: Presenter 2.
Key points:
- EHR answers record retrieval/documentation problem
- Appointment management answers scheduling problem
- Risk classification answers prioritization problem
- QR/NFC passport answers emergency information access problem
- Nurse alerting answers incident coordination problem
- APE, inventory, referrals, and reports support clinic operations
Transition:
"Two features need special explanation because they are central to the study title."

### Slide 9 - Patient Risk Classification
Purpose: Explain the prioritization feature.
Time: 55 seconds.
Presenter: Presenter 2.
Key points:
- Uses rule-based scoring
- Considers symptoms, temperature, pulse, blood pressure, and critical keywords
- Outputs Low, Moderate, High, or Critical
- Decision-support only; final judgment belongs to clinic personnel
Transition:
"For emergency response, the system also uses tokenized passport access and alerting."

### Slide 10 - QR/NFC Passport and Nurse Alerting
Purpose: Explain the emergency workflow.
Time: 1 minute 10 seconds.
Presenter: Presenter 2.
Key points:
- QR/NFC-ready tokenized link
- Emergency-approved information is separated from full records
- Incident reports can create nurse alerts
- Alerts appear on staff dashboard through near real-time polling
Transition:
"To prove that the proposed design is feasible, we will now show a short prototype demonstration."

### Slide 11 - System Proof-of-Concept Demo
Purpose: Show only the parts that support Chapter 1 and 2.
Time: 3 minutes 30 seconds.
Presenter: Presenter 3.
Demo route:
1. Staff dashboard
2. Patient profile / EHR
3. Record visit with risk classification
4. Appointment request or appointment queue
5. Passport or nurse alert page
6. Briefly mention APE, inventory, and reports as supporting modules
Transition:
"This demonstration shows that the system design is feasible, while the project still has defined limitations."

### Slide 12 - Conclusion
Purpose: Close by tying system back to the study.
Time: 55 seconds.
Presenter: Presenter 3.
Key points:
- CLINiQ responds directly to the problems in Chapter 1
- Chapter 2 supports the chosen concepts
- Prototype demonstrates feasibility
- Further development will focus on hardening, deployment, and stronger security controls
Closing:
"That concludes our presentation. Thank you, and we are ready for questions."

## 15-Minute Timing

| Segment | Time |
|---|---:|
| Title and introduction | 1:15 |
| Background and problem | 2:20 |
| Scope and conceptual framework | 2:25 |
| Chapter 2 concepts and gap | 2:00 |
| Core features and key technologies | 3:25 |
| Short system proof-of-concept demo | 3:30 |
| Conclusion | 0:55 |
| Total | 14:50 |

## Speaker Distribution

Presenter 1:
- Title
- Introduction
- Background
- Statement of the Problem
- Scope and Limitation

Presenter 2:
- Conceptual Framework
- Chapter 2 Concepts and Gap
- Core Features
- Risk Classification
- QR/NFC Passport and Nurse Alerting

Presenter 3:
- Short system demo
- Current prototype limitations
- Conclusion

## Demo Plan For Chapter 1-2 Defense

Keep this demo around 3 to 4 minutes.

### Demo Step 1 - Dashboard
Show:
- Visits today
- Pending alerts
- Appointment requests
- APE queue
Say:
"This dashboard shows how the proposed system centralizes clinic operations in one staff view."

### Demo Step 2 - Patient Profile / EHR
Show:
- Patient identity
- Emergency information
- Visit timeline
- APE documents/referrals
Say:
"This addresses the record retrieval and documentation problem from Chapter 1."

### Demo Step 3 - Record Visit With Risk Classification
Show:
- Enter student ID
- Add complaint, symptoms, vitals
- Save visit
- Show generated risk level and reasons
Say:
"This supports patient prioritization. It is rule-based decision support, not diagnosis."

### Demo Step 4 - Appointment Or Alert Workflow
Choose one depending on time:
- Appointment request/approval, or
- Emergency passport/alert workflow
Say:
"This demonstrates how the system supports coordination between students, clinic staff, and emergency response."

### Demo Step 5 - Supporting Modules
Briefly point to:
- APE
- Inventory
- Reports
Say:
"These modules support the broader clinic workflow, but the main study focus remains records, prioritization, appointments, and emergency response."

## Claims To Say Carefully

Use:
- "working prototype"
- "proposed integrated system"
- "rule-based decision support"
- "QR/NFC-ready tokenized link"
- "near real-time alerting"
- "supports clinic workflow"

Avoid:
- "fully deployed system"
- "AI diagnosis"
- "instant alerts"
- "complete hospital integration"
- "automatic parent/hospital notification"
- "fully production-ready QR/NFC infrastructure"

## Best Final Framing

Say this near the beginning:

> "Since this presentation focuses on Chapters 1 and 2, we will emphasize the problem, study objectives, related concepts, and conceptual framework. The system demonstration will be used only as proof that our proposed solution is feasible."

Say this before the demo:

> "We will not show every page of the system. Instead, we will demonstrate the parts that directly answer our research problems."

Say this in the conclusion:

> "The current prototype shows that CLINiQ can integrate the clinic workflows identified in our paper, and the next phase will focus on further validation, security hardening, and deployment improvements."
