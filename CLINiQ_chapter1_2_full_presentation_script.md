# CLINiQ Chapter 1-2 Full Presentation Script

Target length: 14 to 15 minutes  
Format: Three presenters  
Framing: Chapter 1 and Chapter 2 first, current system as proof-of-concept

## Presenter Assignment

Presenter 1:
- Title
- Introduction
- Background of the Study
- Statement of the Problem
- Scope and Limitation

Presenter 2:
- Conceptual Framework
- Chapter 2 connection
- Core Features
- Risk Classification
- QR/NFC Passport and Nurse Alerting

Presenter 3:
- System demo
- Current limitations
- Conclusion

## Opening Reminder

This presentation should not sound like the system is already a perfect production system. The strongest framing is:

> CLINiQ is a working prototype based on the problems and concepts discussed in Chapters 1 and 2.

Use these terms:
- working prototype
- proposed integrated system
- rule-based decision support
- QR/NFC-ready tokenized link
- near real-time alerting

Avoid these terms:
- fully deployed system
- AI diagnosis
- instant alerts
- complete hospital integration
- automatic parent or hospital notification

---

# Full Script

## Slide 1 - Title

Presenter 1:

Good day everyone. We are presenting our capstone project entitled **CLINiQ: A Smart Clinic Information Management System with QR/NFC Patient Health Passport and Real-Time Nurse Alerting for the Clinic of Pamantasan ng Lungsod ng Pasig**.

We are from BSIT 3D, College of Computer Studies. I am [Presenter 1 name], together with [Presenter 2 name] and [Presenter 3 name].

Since this presentation focuses on Chapters 1 and 2, we will emphasize the background of the study, statement of the problem, scope and limitation, conceptual framework, and related concepts. After that, we will show a short system demonstration as proof that our proposed solution is feasible.

Transition:

Before discussing the system itself, let us first explain why this study was developed.

## Slide 2 - Introduction

Presenter 1:

CLINiQ is a web-based clinic information management system designed for the clinic workflow of Pamantasan ng Lungsod ng Pasig.

The idea of the system is to help the clinic manage patient records, appointments, clinic visits, emergency information, nurse alerts, APE tracking, inventory, referrals, and reports in one integrated platform.

For this stage of the capstone, we are presenting CLINiQ as a working prototype. This means the system already demonstrates the major workflows, but some features can still be improved further during the next phase of development.

The main purpose of CLINiQ is to support faster record retrieval, better clinic documentation, more organized appointment handling, and improved emergency coordination.

Transition:

To understand why this system is needed, let us look at the background of the study.

## Slide 3 - Background of the Study

Presenter 1:

In a school clinic, many health-related transactions happen daily. Students may visit for consultation, pain management, wound care, health monitoring, APE requirements, or emergency concerns.

When these processes rely heavily on paper records or separate manual logs, several problems can happen.

First, retrieving patient information can take time, especially when the clinic needs previous visit history, allergies, existing conditions, or guardian contact details.

Second, documentation can become incomplete or fragmented because records may be written in different forms or logbooks.

Third, emergency response can be affected when critical student health information is not immediately accessible.

Fourth, clinic personnel may have difficulty prioritizing patients when several students need attention at the same time.

Because of these problems, our study proposes CLINiQ as an integrated digital solution for school clinic operations.

Transition:

From this background, we formulated the general and specific problems of the study.

## Slide 4 - Statement of the Problem

Presenter 1:

The general problem of the study is that the PLP clinic still experiences manual and fragmented processes for records, appointments, and emergency response. These can cause delays, incomplete data, and slower coordination.

Specifically, the study seeks to answer the following questions.

First, how can CLINiQ improve the management, accessibility, and security of electronic health records?

Second, how can appointment management improve clinic workflow and scheduling efficiency?

Third, how can patient risk classification assist clinic personnel in prioritizing patients based on urgency?

Fourth, how can the QR/NFC health passport and nurse alerting support emergency preparedness?

And fifth, how can the integrated system improve overall quality based on ISO/IEC 25010?

These questions guide the features included in the system. Each major feature of CLINiQ is connected to a specific problem identified in Chapter 1.

Transition:

Next, we define what is included in the system and what is outside its current scope.

## Slide 5 - Scope and Limitation

Presenter 1:

The scope of CLINiQ includes electronic health records, patient and clinic visit management, appointment management, patient risk classification, QR/NFC-ready emergency health passport, nurse alerting, APE tracking, inventory management, referral records, and basic reports.

The system also includes a student portal where students can check APE status, request appointments, and manage emergency health passport information.

However, CLINiQ also has limitations.

It is designed for a single-school clinic setup, specifically for the PLP clinic context. It does not include telemedicine services, hospital system integration, or automatic parent and hospital notifications.

The risk classification feature does not diagnose patients. It only provides rule-based decision support to help clinic personnel prioritize cases.

Also, some features are still prototype-level and can be strengthened further, such as deployment hardening, stricter role-based access control, and more secure document upload handling.

Transition:

Now that the scope is clear, I will turn over the discussion to Presenter 2 for the conceptual framework and Chapter 2 connection.

## Slide 6 - Concept of the Study / Conceptual Framework

Presenter 2:

Thank you. This slide presents the concept of the study.

The framework can be understood as an input-process-output model.

The inputs include patient information, student or staff identification, symptoms, vital signs, appointment requests, APE records, emergency reports, inventory data, and referral information.

These inputs are processed through the CLINiQ core system. The main modules include electronic health records, clinic visit recording, appointment management, patient risk classification, QR/NFC health passport, nurse alerting, APE workflow tracking, inventory, referrals, and reports.

The outputs include organized patient records, visit history, risk level results, nurse alerts, appointment status, APE progress, inventory warnings, referral records, and clinic reports.

In short, the concept of the study is to transform separate clinic transactions into a centralized and traceable digital workflow.

Transition:

This framework is supported by the concepts and related studies discussed in Chapter 2.

## Chapter 2 Connection - Related Concepts and Gap

Presenter 2:

Although the current PowerPoint focuses more on Chapter 1, Chapter 2 is also important because it supports the design of CLINiQ.

The first important concept is the electronic health record. EHR systems support centralized storage and retrieval of patient information. This relates directly to our objective of improving clinic record management.

The second concept is appointment management. Appointment systems help organize schedules, reduce walk-in congestion, and support better clinic planning.

The third concept is QR and NFC technology. QR codes and NFC tags can provide faster access to selected information. In CLINiQ, this idea is applied to the emergency health passport through a tokenized emergency link.

The fourth concept is alerting or notification systems. These support faster incident communication and response coordination. In our system, nurse alerts help clinic personnel see emergency or high-priority cases without relying only on manual reporting.

The fifth concept is risk classification. In CLINiQ, this is rule-based decision support. It helps classify cases as Low, Moderate, High, or Critical based on symptoms and vital signs, but it does not replace the professional judgment of clinic personnel.

The research gap is that many of these concepts are usually discussed or implemented separately. CLINiQ integrates them into one school clinic prototype designed for the PLP clinic context.

Transition:

Based on these concepts, the system was designed with several core features.

## Slide 7 - Core Features

Presenter 2:

The core features of CLINiQ are connected to the problems and objectives of the study.

First is electronic health records. This helps centralize student health information, visit history, emergency details, APE records, and referrals.

Second is appointment management. Students can request appointments, and clinic staff can approve, cancel, complete, or mark appointments based on clinic availability.

Third is patient risk classification. When a visit is recorded, the system calculates a risk level using rule-based criteria.

Fourth is the QR/NFC-ready health passport. Students can manage emergency-approved information such as allergies, blood type, existing conditions, emergency instructions, and guardian contact.

Fifth is nurse alerting. Alerts can be created from emergency reports, manual submissions, or high-risk visit escalation.

The system also includes supporting modules such as APE workflow tracking, inventory management, referral records, and reports.

These modules make CLINiQ an integrated clinic management prototype rather than a single-purpose record system.

Transition:

Two features are especially important to explain because they are part of the title of the study: patient risk classification and emergency response.

## Slide 8 - Patient Risk Classification

Presenter 2:

Patient risk classification is a decision-support feature.

When clinic personnel record a visit, they can enter symptoms and vital signs such as temperature, blood pressure, and pulse rate.

The system checks these values using predefined rules. For example, high fever, abnormal pulse rate, elevated blood pressure, low blood pressure, or critical symptom keywords can increase the risk score.

The output is one of four urgency levels: Low, Moderate, High, or Critical.

This helps clinic personnel prioritize patients more consistently, especially when multiple students need assistance.

However, it is important to clarify that this is not artificial intelligence and it does not diagnose the patient. The final assessment and action still depend on the nurse or authorized clinic personnel.

Transition:

The next feature supports emergency response through the health passport and nurse alert workflow.

## Slide 9 - QR/NFC Emergency Health Passport

Presenter 2:

The QR/NFC emergency health passport is designed to provide faster access to emergency-approved student information.

In the prototype, the passport uses a tokenized link that can be represented as a QR code or NFC-ready link.

The student can manage important emergency information, such as blood type, allergies, existing conditions, emergency instructions, and guardian contact.

The purpose is not to expose the student's full medical record. Full clinic records remain protected inside the authenticated system.

The passport is focused only on information that may help during an emergency and on creating a faster path for reporting incidents to the clinic.

Transition:

Once an emergency is reported, the nurse alerting feature supports communication and coordination.

## Slide 10 - Real-Time Nurse Alerting

Presenter 2:

The nurse alerting feature improves emergency communication inside the clinic workflow.

In CLINiQ, alerts can come from different sources. A user can submit an emergency alert, an incident can be reported through the emergency passport, or a high-risk clinic visit can trigger an alert depending on the risk settings.

These alerts appear on the staff dashboard and alerts page so clinic personnel can acknowledge and monitor them.

Technically, this is near real-time alerting through polling. This means the system checks for new alerts at short intervals instead of requiring the user to manually refresh the page.

It is not the same as instant push notification or WebSocket-based real-time communication, but it is fast enough to demonstrate the emergency coordination workflow in the prototype.

Transition:

Now that we have explained the paper context and system concepts, Presenter 3 will show a short proof-of-concept demonstration.

## Slide 11 - System Demo Overview

Presenter 3:

Thank you. For the demo, we will not show every page of the system. Instead, we will show the parts that directly answer our research problems.

The demonstration will focus on five areas: the staff dashboard, patient profile or electronic health record, visit recording with risk classification, appointment or alert workflow, and supporting modules such as APE, inventory, and reports.

The goal of this demo is to show that the proposed system design is feasible and already represented in the working prototype.

Transition:

Let us begin with the staff dashboard.

## Slide 12 - Demo Screens to Showcase / Live Demo

Presenter 3:

On the staff dashboard, we can see the main clinic overview. It shows metrics such as visits today, pending alerts, low stock, appointment requests, APE clearance, and APE records needing review.

This dashboard supports the objective of centralizing clinic operations because staff can immediately see important clinic activities in one place.

Next, we open a patient profile. The patient profile serves as the electronic health record view. It shows the student's identity, emergency details, visit history, APE documents, and referral records.

This directly addresses the problem of slow record retrieval because clinic personnel no longer need to search through separate paper files just to see the patient's history.

Next, we demonstrate visit recording. The clinic staff enters the student ID, visit purpose, chief complaint, symptoms, and vital signs. After saving the visit, the system generates a risk level and explains the reasons for the score.

For example, if the patient has high fever, abnormal pulse, or critical symptoms, the risk level may become High or Critical. This supports patient prioritization, but again, it is only decision support and not diagnosis.

Next, we show either appointment management or nurse alerting.

For appointment management, students can request a schedule from the student portal, while clinic staff can approve or cancel the request from the staff side.

For nurse alerting, emergency reports can appear in the alert queue. Staff can acknowledge the alert and open the report for more details.

Finally, we briefly show supporting modules such as APE, inventory, and reports. These modules support the broader clinic workflow. APE tracks student requirements and follow-up status. Inventory monitors medicines, low stock, expiring items, and equipment loans. Reports summarize clinic visits, risk distribution, common complaints, and trends.

This demo shows that CLINiQ is not only a concept in the paper. It is already represented by a working prototype that connects the study objectives to actual system workflows.

Transition:

After the demo, we will summarize the current limitations and conclusion of the study.

## Current Prototype Limitations

Presenter 3:

Although the prototype already demonstrates the major workflows, we also recognize its current limitations.

First, CLINiQ is currently designed for a local single-school setup. It is not yet deployed as a production system.

Second, the system has user roles in the database, but stronger role-based restrictions can still be added across all pages.

Third, the QR/NFC feature is implemented as a tokenized emergency link and QR/NFC-ready workflow. Full production NFC writing and deployment can be improved in the next phase.

Fourth, nurse alerting is near real-time through polling, not instant push notification.

Fifth, APE document handling and upload security can still be strengthened further.

These limitations do not remove the value of the prototype. Instead, they define the next development priorities after Chapters 1 and 2.

Transition:

We now move to the conclusion.

## Slide 13 - Conclusion

Presenter 3:

In conclusion, CLINiQ was designed to address the major clinic workflow issues identified in Chapter 1.

The system responds to manual and fragmented records through electronic health records. It responds to scheduling concerns through appointment management. It supports patient prioritization through rule-based risk classification. It supports emergency preparedness through the QR/NFC-ready health passport and nurse alerting. It also includes APE, inventory, referral, and reporting modules to support broader clinic operations.

The related concepts from Chapter 2 support the design of the system, especially electronic health records, appointment management, QR/NFC access, alerting systems, and decision-support classification.

The current prototype demonstrates that the proposed system is feasible. Further development can focus on validation, stronger security, deployment, and production-level integration.

That concludes our presentation. Thank you, and we are ready for your questions.

---

# Short Backup Script If Time Is Running Out

If the presentation is close to exceeding 15 minutes, shorten the demo and use this version:

Presenter 3:

For the proof-of-concept demo, we will show only the most important workflow. First, the dashboard centralizes daily clinic operations such as visits, appointments, alerts, and APE tasks. Second, the patient profile shows how CLINiQ supports electronic health records by keeping identity, emergency information, visit history, APE records, and referrals in one view. Third, the visit recording page shows how symptoms and vitals are entered and how the system generates a rule-based risk level. Finally, the alert or appointment module shows how the system supports clinic coordination. The other modules, such as inventory, reports, and APE, support the broader clinic workflow but will not be discussed deeply due to time.

---

# Quick Q&A Preparation

Question: Is the risk classification AI?

Answer:

No. It is not AI and it does not diagnose. It is a rule-based decision-support feature that scores symptoms and vital signs based on configured thresholds.

Question: Is the nurse alerting truly real-time?

Answer:

It is near real-time through polling. The system checks for new alerts at short intervals, so staff can see updates without manually refreshing the page.

Question: What is the role of QR/NFC?

Answer:

QR/NFC provides a faster way to open a tokenized emergency health passport link. The purpose is to access emergency-approved information and support incident reporting, not to expose full medical records.

Question: Why is the system connected to Chapter 1 and 2?

Answer:

Chapter 1 defines the clinic problems and objectives. Chapter 2 supports the system design through related concepts such as EHR, appointment systems, QR/NFC access, alerts, and risk classification. The system is the proposed solution based on those chapters.

Question: What is still missing or future work?

Answer:

Future work includes stronger role-based access control, production deployment, secure upload hardening, improved audit logs, real QR/NFC integration, and possible SMS or email alert integration.
