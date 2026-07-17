import fs from "node:fs/promises";
import {
  FileBlob,
  PresentationFile,
} from "@oai/artifact-tool";

const sourcePptx = "D:/Downloads/CLINiQ_PPT (1).pptx";
const outputPptx = "C:/xampp/htdocs/CLINiQ/outputs/CLINiQ_15min_adjusted_presentation.pptx";
const qaDir = "C:/xampp/htdocs/CLINiQ/.codex-work/ppt-adjust/qa";

async function writeBlob(path, blob) {
  await fs.writeFile(path, new Uint8Array(await blob.arrayBuffer()));
}

const edits = [
  ["sh/ipovexwn", "BSIT-3D | COLLEGE OF COMPUTER STUDIES"],
  ["sh/sfqdkrep", "A Smart Clinic Information Management System with QR/NFC Patient Health Passport and Nurse Alerting for the Clinic of PLP"],

  ["sh/wbidwb29", "What CLINiQ is, and why it was developed"],
  ["sh/ru5cbqd4", "A web-based school clinic information management system"],
  ["sh/69wbilcj", "Purpose-built for the Clinic of Pamantasan ng Lungsod ng Pasig (PLP)"],
  ["sh/exgvmlcv", "Improves clinic documentation"],
  ["sh/107uh0v6", "Centralizes patient profiles, visits, APE status, appointments, alerts, inventory, and reports."],
  ["sh/0vq1o36x", "Strengthens clinic response"],
  ["sh/1wzix87i", "Gives staff faster access to records, risk levels, alerts, and emergency-approved information."],
  ["sh/d8zit87m", "Supports staff and students"],
  ["sh/q5ozitov", "Includes a staff portal, student portal, self-logbook, and tokenized emergency passport flow."],

  ["sh/bexwjil0", "Where manual clinic processes fall short"],
  ["sh/hgvm1gvq", "Searchable patient profiles and visit history"],
  ["sh/ved4zqdk", "Structured documentation for visits and APE"],
  ["sh/1czmdkvu", "QR/NFC-ready passport + nurse alerts"],
  ["sh/a5s7a9gb", "Transparent rule-based risk classification"],

  ["sh/k72t43md", "PLP Clinic still relies on manual and fragmented workflows for records, appointments, APE tracking, and emergency response, which can delay retrieval, documentation, and coordination."],
  ["sh/10zehwna", "How does the integrated prototype support system quality based on ISO/IEC 25010 criteria?"],

  ["sh/3ed0vyt8", "What the system can and cannot do"],
  ["sh/y983uxcv", "APE Workflow Tracking"],
  ["sh/dgj6p0ru", "Near Real-Time Nurse Alerting"],
  ["sh/cfe9sjat", "Local single-school prototype"],
  ["sh/obu9ozuh", "No direct hospital system integration"],
  ["sh/29crmpcr", "No automatic SMS/parent/hospital alerts"],

  ["sh/or254byx", "Students / Clinic Staff"],
  ["sh/b6pofe10", "Profiles, symptoms, vitals, appointments, APE data"],
  ["sh/gz6l47mx", "CLINiQ PHP/MySQL Core"],
  ["sh/ozi98fy5", "EHR, visits, alerts, APE, inventory, reports"],
  ["sh/83290nmd", "Risk levels, schedules, alerts, reports"],
  ["sh/y5kre9wn", "Improved Campus Clinic Workflow"],

  ["sh/dw3qhkne", "Implemented Core Features"],
  ["sh/excrqp4z", "What the current prototype already supports"],
  ["sh/f218nq5g", "Purpose: Centralizes patient profiles, visits, APE, and referrals"],
  ["sh/xw7uto7y", "Benefit: Faster record retrieval"],
  ["sh/50rux87u", "APE Work Queues"],
  ["sh/kzito369", "Purpose: Tracks document review, submission, follow-up, and clearance"],
  ["sh/jy9cvypo", "Benefit: Clearer APE monitoring"],
  ["sh/pwf69gva", "Purpose: Lets students request consultations for staff approval"],
  ["sh/2t4ne1cz", "Benefit: Better scheduling control"],
  ["sh/f6d436d8", "Purpose: Scores urgency from symptoms, vitals, BP, pulse, and keywords"],
  ["sh/s3m58rux", "Benefit: Consistent prioritization"],
  ["sh/wz25kryp", "Purpose: Provides a tokenized emergency-ready health link"],
  ["sh/j2tofmx0", "Benefit: Faster emergency context"],
  ["sh/6p47q1gr", "Purpose: Sends pending alerts through dashboard polling"],
  ["sh/2lori50z", "Benefit: Faster clinic coordination"],

  ["sh/218ba1cz", "Rule-based decision support for nurse prioritization"],
  ["sh/faxsf298", "Temperature"],
  ["sh/l87yxk3q", "Pulse Rate"],
  ["sh/y5gfm54z", "Blood Pressure / Keywords"],
  ["sh/be5c7q1o", "Decision-support only - not a diagnostic AI. Final clinical judgment always remains with the nurse."],

  ["sh/fi1wjql0", "QR/NFC-Ready Emergency Health Passport"],
  ["sh/wn6dgb21", "Tokenized access to emergency-approved student health information"],
  ["sh/l4nyh4na", "Student Health Passport"],
  ["sh/d0nyd4ny", "QR Scan or NFC Tap"],
  ["sh/65szqp83", "Token Validation"],
  ["sh/e9czup8f", "Emergency Profile / Report"],
  ["sh/ap0nixcr", "Emergency-approved details are separated from full clinic records, which remain protected behind staff login."],

  ["sh/b21crel4", "Near Real-Time Nurse Alerting"],
  ["sh/a1svit4z", "Improving emergency communication and clinic coordination"],
  ["sh/9knylsni", "Student/Teacher submits report"],
  ["sh/ri90f6pw", "Clinic dashboard receives alert"],
  ["sh/1gnq1g3i", "Alerts appear on the staff dashboard through lightweight polling, so clinic personnel can monitor incidents, coordinate response, and log actions taken in one place."],

  ["sh/jyhozyt8", "Live Demo Roadmap"],
  ["sh/ix8n6dcn", "The focused workflow for the recorded presentation"],
  ["sh/psjmtsri", "Student login/request"],
  ["sh/f214ni90", "Staff dashboard"],
  ["sh/mhgbylgb", "Approve appointment"],
  ["sh/4bid8fe9", "Record clinic visit"],
  ["sh/ful8r2dg", "Risk classification"],
  ["sh/ihcn6tgf", "Patient profile"],
  ["sh/wfa543y9", "Passport incident"],
  ["sh/ruxkzaxk", "Nurse alert response"],
  ["sh/9oj294fy", "APE / Reports"],

  ["sh/ju1o7ehg", "Current Status & Next Improvements"],
  ["sh/yt8nytgv", "What is working now, and what should be strengthened next"],
  ["sh/rqdk7qx0", "Working Staff Portal"],
  ["sh/zudkbaxw", "Student Portal"],
  ["sh/fytkz6xs", "Patient Profile"],
  ["sh/k7ad4bat", "APE Tracking"],
  ["sh/8bad8bap", "Role Restrictions"],
  ["sh/tkvaxs7y", "Secure File Upload"],
  ["sh/5gvat87m", "QR/NFC Production"],
  ["sh/pcfa5c7q", "SMS/Email Alerts"],
  ["sh/xone9c7e", "Reports Expansion"],

  ["sh/kvm5onal", "A working prototype for more coordinated campus clinic operations"],
  ["sh/6h4nqdsr", "PROBLEMS ADDRESSED"],
  ["sh/dsr2h4rq", "SOLUTIONS IMPLEMENTED"],
  ["sh/nyt4ne9s", "QR/NFC-Ready Passport"],
  ["sh/k3uhob6p", "Nurse Alerting"],
  ["sh/i1czm1oj", "PROTOTYPE BENEFITS"],
  ["sh/s7uhsb61", "Faster clinic coordination"],
  ["sh/gbe1wv6x", "More efficient daily workflow"],
];

async function main() {
  await fs.mkdir("C:/xampp/htdocs/CLINiQ/outputs", { recursive: true });
  await fs.mkdir(qaDir, { recursive: true });

  const presentation = await PresentationFile.importPptx(await FileBlob.load(sourcePptx));

  for (const [id, text] of edits) {
    const target = presentation.resolve(id);
    target.text = text;
  }

  const notes = [
    "Presentation pacing target: maximum 15 minutes.",
    "Demo focus: student appointment request -> staff approval -> visit recording -> risk result -> patient profile -> passport incident -> nurse alert -> APE/reports proof.",
    "Defense phrasing: rule-based decision support, tokenized QR/NFC-ready emergency link, near real-time dashboard polling.",
  ];

  for (const slide of presentation.slides.items) {
    if (slide.speakerNotes?.textFrame) {
      slide.speakerNotes.textFrame.setText(notes.join("\n"));
      slide.speakerNotes.setVisible?.(false);
    }
  }

  const montage = await presentation.export({ format: "webp", montage: true, scale: 1 });
  await writeBlob(`${qaDir}/adjusted-montage.webp`, montage);

  for (const [index, slide] of presentation.slides.items.entries()) {
    const stem = `slide-${String(index + 1).padStart(2, "0")}`;
    await writeBlob(`${qaDir}/${stem}.png`, await presentation.export({ slide, format: "png", scale: 1 }));
    await fs.writeFile(`${qaDir}/${stem}.layout.json`, await (await slide.export({ format: "layout" })).text());
  }

  const pptx = await PresentationFile.exportPptx(presentation);
  await pptx.save(outputPptx);
  console.log(outputPptx);
}

main().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
