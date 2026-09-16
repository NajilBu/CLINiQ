UPDATE patients pt
LEFT JOIN students s ON s.person_id = pt.person_id
SET pt.access_status = 'Official'
WHERE s.person_id IS NULL
  AND pt.access_status = 'Applicant';
