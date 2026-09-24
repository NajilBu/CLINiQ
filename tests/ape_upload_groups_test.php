<?php
// Run: php tests/ape_upload_groups_test.php
// Read-only CTE fixtures shadow the three APE tables for each SELECT.
require_once __DIR__ . '/../app/config/database.php';
require_once __DIR__ . '/../app/services/ApeWorkflow.php';

function expect(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$db = auth_db();
$seed = $db->query('SELECT * FROM ape_records ORDER BY ape_id LIMIT 1')->fetch();
expect((bool) $seed, 'One existing APE identity is needed for read-only join fixtures.');
$seed = array_replace($seed, ['exam_date'=>'2026-09-03', 'patient_vitals_status'=>'Confirmed', 'workflow_status'=>'Follow-up Required', 'clearance_status'=>'For Follow-up', 'follow_up_required'=>1, 'requirement_status'=>'Not Checked', 'requirements_saved_at'=>'2026-09-03 14:00:00', 'follow_up_due_date'=>'2026-09-15']);
$apeId = (int) $seed['ape_id'];
$fixturePath = tempnam(ape_document_storage_root(), 'ape-test-');
expect($fixturePath !== false, 'A temporary APE document fixture is required.');
file_put_contents($fixturePath, "%PDF-1.4\nfixture\n");
$fixtureRelativePath = 'storage/documents/ape/' . basename($fixturePath);
register_shutdown_function(static function () use ($fixturePath): void {
    if (is_file($fixturePath)) unlink($fixturePath);
});
$requirements = [];
foreach (['Custom Initial A', 'Custom Initial B', 'Deferred TB Cert'] as $i => $name) {
    $requirements[] = ['requirement_id'=>$i+1, 'ape_id'=>$apeId, 'requirement_name'=>$name, 'status'=>$i===2?'Missing':'Verified', 'remarks'=>$i===2?'Return certificate':null, 'upload_group'=>$i===2?'follow_up':'initial', 'upload_due_date'=>$i===2?'2026-09-15':'2026-09-10'];
}
function cteRows(array $rows): string {
    global $db;
    $selects=[];
    foreach ($rows as $row) {
        $values=[];
        foreach ($row as $key=>$value) $values[]=($value===null?'NULL':(is_int($value)?(string)$value:$db->quote((string)$value))).' AS `'.$key.'`';
        $selects[]='SELECT '.implode(', ', $values);
    }
    return implode(' UNION ALL ', $selects);
}
function upload(int $id, string $name, string $status): array {
    global $apeId, $fixtureRelativePath;
    return ['document_id'=>$id,'ape_id'=>$apeId,'document_type'=>$name,'verification_status'=>$status,'file_path'=>$fixtureRelativePath,'uploaded_at'=>'2026-09-04 08:00:00','verified_at'=>null,'verified_by_person_id'=>null];
}
function withLatestDocuments(array $requirements, array $documents): array {
    foreach ($requirements as &$requirement) {
        $requirement['_latest_document'] = null;
        foreach ($documents as $document) {
            if (($document['document_type'] ?? '') === ($requirement['requirement_name'] ?? '')) {
                $requirement['_latest_document'] = $document;
                break;
            }
        }
    }
    unset($requirement);
    return $requirements;
}
function fixtureRecord(array $requirements, array $documents=[], array $overrides=[]): array {
    global $db,$seed;
    $docSql=cteRows($documents ?: [upload(0,'None','Pending')]);
    if (!$documents) $docSql.=' WHERE 1=0';
    $sql='WITH ape_records AS ('.cteRows([array_replace($seed,$overrides)]).'), ape_requirements AS ('.cteRows($requirements).'), ape_documents AS ('.$docSql.') '.ape_record_select_sql();
    $rows=$db->query($sql)->fetchAll();
    expect(count($rows)===1,'Fixture must return one record.');
    return $rows[0];
}
$r=fixtureRecord($requirements);
expect((int)$r['initial_requirement_count']===2 && (int)$r['deferred_requirement_count']===1,'Dynamic groups include custom requirements.');
$regularRequirements=array_slice($requirements,0,2);
$regularOpen=['workflow_status'=>'Requirements Checked','clearance_status'=>'Pending','follow_up_required'=>0,'requirement_status'=>'Checked','follow_up_due_date'=>null];
$r=fixtureRecord($regularRequirements,[],$regularOpen);
expect(ape_record_queue($r)==='final_decision','Missing regular files stay in final decision after examination.');
expect(ape_deadline_status($r, null, $regularRequirements, [])['due_date']==='2026-09-10','Initial group has examination +7 days while in final decision.');
$initial=[upload(101,'Custom Initial A','Verified'),upload(102,'Custom Initial B','Verified')];
expect(ape_record_queue(fixtureRecord($regularRequirements,[$initial[0]],$regularOpen))==='final_decision','Partial regular upload remains visible in final decision.');
$pending=$initial; $pending[1]['verification_status']='Pending';
$submittedPending = fixtureRecord($regularRequirements, $pending, $regularOpen);
expect(ape_deadline_status($submittedPending, new DateTimeImmutable('2026-09-11'), $regularRequirements, $pending) === null, 'A complete student upload awaiting clinic archive review is not overdue.');
expect(ape_priority_badge($submittedPending)['label'] !== 'Overdue', 'A complete student upload awaiting clinic archive review does not enter reminder alerts.');
expect(ape_record_queue(fixtureRecord($regularRequirements,$pending,$regularOpen))==='final_decision','Archive review remains in final decision.');
$r=fixtureRecord($requirements,$initial);
expect(ape_record_queue($r)==='follow_up','Initial archive advances without deferred file.');
expect(ape_deadline_status($r, null, $requirements, $initial)['due_date']==='2026-09-15','Follow-up retains assigned deadline.');
expect(ape_phase_three_review_group($r, $requirements) === 'follow_up', 'Phase 3 defaults to the active follow-up document group.');
$replacementRequirements = $requirements;
$replacementRequirements[2]['status'] = 'Needs Correction';
$replacementDocuments = [...$initial, upload(104, 'Deferred TB Cert', 'Pending')];
$replacementStates = ape_phase_three_requirement_states(withLatestDocuments($replacementRequirements, $replacementDocuments), 'follow_up');
expect(count($replacementStates['ready']) === 1 && count($replacementStates['waiting']) === 0, 'A submitted follow-up replacement is ready for clinic review.');
$replacementStates = ape_phase_three_requirement_states(withLatestDocuments($requirements, $initial), 'follow_up');
expect(count($replacementStates['ready']) === 0 && count($replacementStates['waiting']) === 1, 'A missing follow-up requirement remains separately waiting on the student.');
$earlyReturn = array_replace($r, ['follow_up_due_date'=>'2026-09-04', 'deferred_upload_due_date'=>'2026-09-04']);
foreach (['2026-09-03', '2026-09-04'] as $day) {
    $deadline = ape_deadline_status($earlyReturn, new DateTimeImmutable($day), $requirements, $initial);
    expect($deadline['label'] === 'On Track', 'Follow-up remains on track through its assigned return date: '.$day);
    expect($deadline['due_date'] === '2026-09-15', 'Follow-up uses the individual requirement due date instead of the record-level fallback.');
}
expect(ape_deadline_status($earlyReturn, new DateTimeImmutable('2026-09-05'), $requirements, $initial)['label'] === 'On Track', 'Follow-up does not warn before its individual requirement due date.');
expect(ape_deadline_status($r, new DateTimeImmutable('2026-09-14'), $requirements, $initial)['label'] === 'On Track', 'No due-tomorrow urgent warning.');
expect(ape_deadline_status($r, new DateTimeImmutable('2026-09-15'), $requirements, $initial)['label'] === 'On Track', 'Allow the entire assigned due date.');
expect(ape_deadline_status($r, new DateTimeImmutable('2026-09-16'), $requirements, $initial)['label'] === 'Overdue', 'Warn after a later assigned due date passes.');
$followUpWaitingActions = ape_patient_document_action_summaries($r, $requirements, $initial, new DateTimeImmutable('2026-09-14'));
expect(count($followUpWaitingActions) === 1 && $followUpWaitingActions[0]['priority'] === 'waiting_on_patient' && $followUpWaitingActions[0]['due_at'] === '2026-09-15', 'Missing follow-up requirements use their own assigned due date.');
$submittedFollowUp = fixtureRecord($requirements, [...$initial, upload(104, 'Deferred TB Cert', 'Pending')], $regularOpen);
expect(ape_patient_document_action_summaries($submittedFollowUp, $requirements, [...$initial, upload(104, 'Deferred TB Cert', 'Pending')]) === [], 'A submitted Pending follow-up file is clinic review work, not a patient reminder action.');
$followUpOverdueActions = ape_patient_document_action_summaries($r, $requirements, $initial, new DateTimeImmutable('2026-09-16'));
expect($followUpOverdueActions[0]['priority'] === 'overdue' && $followUpOverdueActions[0]['email_event_type'] === 'ape_follow_up_overdue', 'Only an unresolved follow-up requirement becomes overdue after its own due date.');
$initialWaiting = fixtureRecord($regularRequirements, [], $regularOpen);
expect(ape_deadline_status($initialWaiting, new DateTimeImmutable('2026-09-10'), $regularRequirements, [])['label'] === 'On Track', 'Initial upload deadline day is not overdue.');
expect(ape_deadline_status($initialWaiting, new DateTimeImmutable('2026-09-11'), $regularRequirements, [])['label'] === 'Overdue', 'Initial uploads warn after their seven-day deadline.');
$old=upload(99,'Custom Initial B','Needs Correction');
expect(ape_digital_submission_complete(fixtureRecord($requirements,[$old,...$initial])),'Latest verified version supersedes older correction.');
expect(!ape_digital_submission_complete(fixtureRecord($requirements,[...$initial,upload(103,'Custom Initial B','Needs Correction')])),'Latest correction blocks initial archive completion.');
$returned=['requirement_status'=>'Checked','workflow_status'=>'Reviewed','clearance_status'=>'Pending','follow_up_required'=>0];
expect(ape_record_queue(fixtureRecord($requirements,$initial,$returned))==='follow_up','Deferred upload still needed after return review.');
expect(ape_record_queue(fixtureRecord($requirements,[...$initial,upload(104,'Deferred TB Cert','Pending')],$returned))==='follow_up','Deferred file needs archive review.');
expect(ape_record_queue(fixtureRecord($requirements,[...$initial,upload(104,'Deferred TB Cert','Verified')],$returned))==='final_decision','All archived can reach final decision.');
expect(ape_record_queue(fixtureRecord(array_slice($requirements,0,2),$initial,$returned))==='final_decision','No deferred group: initial archive reaches final decision.');
$allDeferred=$requirements; foreach($allDeferred as &$req)$req['upload_group']='follow_up'; unset($req);
expect(ape_record_queue(fixtureRecord($allDeferred))==='follow_up','No initial documents means nothing to wait for in initial group.');
$unassigned=$requirements; $unassigned[0]['upload_group']=null;
expect(ape_digital_submission_complete(fixtureRecord($unassigned,$initial)),'Legacy unassigned requirements are treated as initial uploads in the digital-first flow.');
expect(ape_record_queue(fixtureRecord($requirements,$initial,['exam_date'=>null]))==='examination','Exam prerequisite preserved.');

class GroupTestStatement extends PDOStatement {
    public function __construct(private GroupTestPDO $owner, private string $sql) {}
    public function execute(?array $params=null): bool { $this->owner->calls[]=[$this->sql,$params]; return true; }
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->owner->rows; }
}
class GroupTestPDO extends PDO {
    public array $calls=[];
    public function __construct(public array $rows=[]) {}
    public function prepare(string $query, array $options=[]): PDOStatement|false { return new GroupTestStatement($this,$query); }
}
// Exercise the production archive branch with a fake connection, replacing only its requirement fetch.
$source=file_get_contents(__DIR__.'/../public/ape/view.php');
$start=strpos($source,'$archiveQueue = ape_record_queue($record);');
$end=strpos($source,"} elseif (\$action === 'request_document_correction')",$start);
$archive=str_replace(['ape_requirements_for_record($id)', 'ape_findings_for_record($id)'], ['$requirementsFixture', '$findingsFixture'], substr($source,$start,$end-$start));
$canRecordApeExam=true; $findingsFixture=[];
$requirementsFixture=withLatestDocuments($regularRequirements, $pending);
$record=fixtureRecord($regularRequirements,$pending,$regularOpen); $id=$apeId; $staffPersonId=1;
$apeDb=new GroupTestPDO($pending); eval($archive);
$writes=array_values(array_filter($apeDb->calls,static fn($call)=>str_starts_with($call[0],'UPDATE')));
expect(count($writes)===3,'Archive verifies the ready file, its requirement, and workflow state.');
expect($writes[0][1]===[$staffPersonId,$id,102],'Archive targets only the Pending file that is ready now.');
expect($writes[1][1]===[$staffPersonId,$id,'Custom Initial B'],'Archive marks the ready requirement as verified.');
expect($writes[2][1][0]==='Reviewed','Regular initial archive keeps the patient in final decision.');
$requirementsFixture=withLatestDocuments($requirements, [...$initial, upload(104, 'Deferred TB Cert', 'Pending')]);
$record=fixtureRecord($requirements,[...$initial,upload(104,'Deferred TB Cert','Pending')],$returned);
$apeDb=new GroupTestPDO([...$initial,upload(104,'Deferred TB Cert','Pending')]); eval($archive);
$writes=array_values(array_filter($apeDb->calls,static fn($call)=>str_starts_with($call[0],'UPDATE')));
expect($writes[0][1]===[$staffPersonId,$id,104],'Deferred archive targets only returned group.');
expect(count($writes)===3 && $writes[2][1]===[0,'Reviewed','Pending',$staffPersonId,$id], 'Deferred archive verifies requirements and moves to final decision.');
expect($writes[1][1]===[$staffPersonId,$id,'Deferred TB Cert'], 'Only the ready deferred requirement is accepted; deadlines and remarks remain unchanged.');
foreach ([null, '2026-09-03 14:00:00'] as $savedAt) {
    $requirementsFixture=withLatestDocuments($requirements,[...$initial,upload(104,'Deferred TB Cert','Pending')]);
    $record=fixtureRecord($requirements,[...$initial,upload(104,'Deferred TB Cert','Pending')],['requirements_saved_at'=>$savedAt]);
    $apeDb=new GroupTestPDO([...$initial,upload(104,'Deferred TB Cert','Pending')]); eval($archive);
    $writes=array_values(array_filter($apeDb->calls,static fn($call)=>str_starts_with($call[0],'UPDATE')));
    expect($writes[2][1][0]===0, 'Direct archive works without a separate hard-copy review, including old unlocked record.');
}
$record = array_replace($record, ['clinical_follow_up_required' => 1]);
$apeDb=new GroupTestPDO([...$initial,upload(104,'Deferred TB Cert','Pending')]); eval($archive);
$writes=array_values(array_filter($apeDb->calls,static fn($call)=>str_starts_with($call[0],'UPDATE')));
expect($writes[2][1][0]===1 && $writes[2][1][1]==='Follow-up Required','Clinical follow-up remains open.');
$record = array_replace($record, ['clinical_follow_up_required' => 0]);
foreach (['correction', 'hard_copy_pending'] as $case) {
    $record=fixtureRecord($requirements,$pending);
    $rows=$pending;
    if($case==='missing') array_pop($rows);
    if($case==='correction') $rows[1]['verification_status']='Needs Correction';
    if($case==='hard_copy_pending') $record=fixtureRecord($requirements,$initial);
    $apeDb=new GroupTestPDO($rows); $rejected=false;
    try { eval($archive); } catch(RuntimeException $e) { $rejected=true; }
    expect($rejected, 'Reject archive: '.$case);
    expect(!array_filter($apeDb->calls,static fn($call)=>str_starts_with($call[0],'UPDATE')), 'Rejected archive makes no writes.');
}
$record=fixtureRecord($requirements,[...$initial,upload(104,'Deferred TB Cert','Pending')]);
$apeDb=new GroupTestPDO([...$initial,upload(104,'Deferred TB Cert','Pending')]);
eval($archive);
$writes=array_values(array_filter($apeDb->calls,static fn($call)=>str_starts_with($call[0],'UPDATE')));
expect($writes[0][1]===[$staffPersonId,$id,104], 'A ready follow-up file can be archived without requiring unrelated missing files.');

// The Phase 3 workspace owns group selection and separates ready files from
// requirements still waiting on the student.
expect(str_contains($source, 'Document groups to review') && str_contains($source, 'Still waiting on the student') && str_contains($source, 'Files ready for review'), 'Phase 3 renders one grouped document-review workspace with separate ready and waiting states.');
expect(str_contains($source, 'name="review_group"') && str_contains($source, 'value="approve_documents"') && str_contains($source, 'value="request_document_correction"'), 'Archive and return actions submit the selected review group.');
expect(!str_contains($source, 'hard_copy_status') && !str_contains($source, 'Save Document Review'), 'Retired hard-copy checklist controls are absent.');
$attentionSource = file_get_contents(__DIR__.'/../app/services/WorkflowAttention.php');
expect(!str_contains($attentionSource, "WHERE r.status <> 'Verified'"), 'Workflow Attention relies on the shared patient-action resolver instead of a broad requirement query.');
$workCenterSource = file_get_contents(__DIR__.'/../app/services/ClinicWorkCenter.php');
expect(!str_contains($workCenterSource, "'process' => 'cancel_email'"), 'Queued emails are never mapped to a destructive cancel action from Email Center attention.');
$portal=file_get_contents(__DIR__.'/../patient-portal/patient-ape-status.php');
expect(str_contains($portal, 'optimizeApeImage') && str_contains($portal, 'XMLHttpRequest'), 'Student uploads optimize images and report upload progress without waiting on a blank page.');
expect(str_contains($portal, 'ape-upload-progress-bar') && str_contains($portal, 'Uploading documents'), 'Student upload progress UI is present.');
expect(str_contains($portal, "'preview_type' => \$previewType") && str_contains($portal, 'data-preview-type="<?= student_e($doc[\'preview_type\']) ?>"'), 'Patient document previews explicitly identify images and PDFs for the shared preview dialog.');
$cardStart=strpos($portal,'$requirementByName = array_column($requirements');
$cardEnd=strpos($portal,'$uploadableDocumentCount =',$cardStart);
$documents=[['name'=>'Custom Initial A'],['name'=>'Deferred TB Cert']];
$missingItems = '';
eval(substr($portal,$cardStart,$cardEnd-$cardStart));
expect($documents[0]['upload_due_date']==='2026-09-10' && $documents[1]['upload_due_date']==='2026-09-15','Patient cards receive individual deadlines.');
echo "PASS: query groups, deadlines, latest-version gates, empty/legacy cases, review save, initial and deferred archive. No patient writes.\n";
