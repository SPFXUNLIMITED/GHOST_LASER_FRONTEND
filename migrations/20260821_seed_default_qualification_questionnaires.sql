INSERT INTO qualification_questionnaires (name, description)
SELECT 'New Machine - Never Worked Right', 'For new installs that have never cut correctly.'
WHERE NOT EXISTS (
    SELECT 1 FROM qualification_questionnaires WHERE name = 'New Machine - Never Worked Right'
);

INSERT INTO qualification_questionnaires (name, description)
SELECT 'Machine Suddenly Stopped Working', 'For machines that previously cut correctly and then failed.'
WHERE NOT EXISTS (
    SELECT 1 FROM qualification_questionnaires WHERE name = 'Machine Suddenly Stopped Working'
);

INSERT INTO qualification_questionnaires (name, description)
SELECT 'Poor Cut Quality', 'For machines that still cut but with poor cut results.'
WHERE NOT EXISTS (
    SELECT 1 FROM qualification_questionnaires WHERE name = 'Poor Cut Quality'
);

-- Core questions
INSERT INTO qualification_questions (questionnaire_id, question_text, question_type)
SELECT q.id, 'What brand and model is your laser cutter?', 'text'
FROM qualification_questionnaires q
WHERE NOT EXISTS (
    SELECT 1
    FROM qualification_questions qq
    WHERE qq.questionnaire_id = q.id
      AND qq.question_text = 'What brand and model is your laser cutter?'
);

INSERT INTO qualification_questions (questionnaire_id, question_text, question_type)
SELECT q.id, 'How old is this machine? (New, less than 6 months, 6-18 months, over 18 months)', 'single_choice'
FROM qualification_questionnaires q
WHERE NOT EXISTS (
    SELECT 1
    FROM qualification_questions qq
    WHERE qq.questionnaire_id = q.id
      AND qq.question_text = 'How old is this machine? (New, less than 6 months, 6-18 months, over 18 months)'
);

INSERT INTO qualification_questions (questionnaire_id, question_text, question_type)
SELECT q.id, 'When did you purchase this machine?', 'date'
FROM qualification_questionnaires q
WHERE NOT EXISTS (
    SELECT 1
    FROM qualification_questions qq
    WHERE qq.questionnaire_id = q.id
      AND qq.question_text = 'When did you purchase this machine?'
);

INSERT INTO qualification_questions (questionnaire_id, question_text, question_type)
SELECT q.id, 'Who assembled and installed the machine when you received it? (We installed it, Seller/Technician installed it, I installed it myself)', 'single_choice'
FROM qualification_questionnaires q
WHERE NOT EXISTS (
    SELECT 1
    FROM qualification_questions qq
    WHERE qq.questionnaire_id = q.id
      AND qq.question_text = 'Who assembled and installed the machine when you received it? (We installed it, Seller/Technician installed it, I installed it myself)'
);

INSERT INTO qualification_questions (questionnaire_id, question_text, question_type)
SELECT q.id, 'Has this machine ever cut properly since you got it? (Yes - it worked great before / No - it has never cut correctly)', 'single_choice'
FROM qualification_questionnaires q
WHERE NOT EXISTS (
    SELECT 1
    FROM qualification_questions qq
    WHERE qq.questionnaire_id = q.id
      AND qq.question_text = 'Has this machine ever cut properly since you got it? (Yes - it worked great before / No - it has never cut correctly)'
);

-- New Machine - Never Worked Right follow-ups
INSERT INTO qualification_questions (questionnaire_id, question_text, question_type)
SELECT q.id, 'Have mirror alignment and focus calibration been checked yet?', 'single_choice'
FROM qualification_questionnaires q
WHERE q.name = 'New Machine - Never Worked Right'
  AND NOT EXISTS (
    SELECT 1
    FROM qualification_questions qq
    WHERE qq.questionnaire_id = q.id
      AND qq.question_text = 'Have mirror alignment and focus calibration been checked yet?'
);

INSERT INTO qualification_questions (questionnaire_id, question_text, question_type)
SELECT q.id, 'What happens during a standard test cut right now?', 'text'
FROM qualification_questionnaires q
WHERE q.name = 'New Machine - Never Worked Right'
  AND NOT EXISTS (
    SELECT 1
    FROM qualification_questions qq
    WHERE qq.questionnaire_id = q.id
      AND qq.question_text = 'What happens during a standard test cut right now?'
);

-- Machine Suddenly Stopped Working follow-ups
INSERT INTO qualification_questions (questionnaire_id, question_text, question_type)
SELECT q.id, 'What changed right before the issue started? (Power event, software/settings change, new material, no known change)', 'single_choice'
FROM qualification_questionnaires q
WHERE q.name = 'Machine Suddenly Stopped Working'
  AND NOT EXISTS (
    SELECT 1
    FROM qualification_questions qq
    WHERE qq.questionnaire_id = q.id
      AND qq.question_text = 'What changed right before the issue started? (Power event, software/settings change, new material, no known change)'
);

INSERT INTO qualification_questions (questionnaire_id, question_text, question_type)
SELECT q.id, 'Are there any alarms, error codes, or unusual sounds right now?', 'text'
FROM qualification_questionnaires q
WHERE q.name = 'Machine Suddenly Stopped Working'
  AND NOT EXISTS (
    SELECT 1
    FROM qualification_questions qq
    WHERE qq.questionnaire_id = q.id
      AND qq.question_text = 'Are there any alarms, error codes, or unusual sounds right now?'
);

-- Poor Cut Quality follow-ups
INSERT INTO qualification_questions (questionnaire_id, question_text, question_type)
SELECT q.id, 'Which issue best matches the cut quality problem? (Not cutting through, excessive charring, inconsistent depth, rough edges)', 'single_choice'
FROM qualification_questionnaires q
WHERE q.name = 'Poor Cut Quality'
  AND NOT EXISTS (
    SELECT 1
    FROM qualification_questions qq
    WHERE qq.questionnaire_id = q.id
      AND qq.question_text = 'Which issue best matches the cut quality problem? (Not cutting through, excessive charring, inconsistent depth, rough edges)'
);

INSERT INTO qualification_questions (questionnaire_id, question_text, question_type)
SELECT q.id, 'Have lens, mirrors, and material settings been cleaned/verified recently?', 'single_choice'
FROM qualification_questionnaires q
WHERE q.name = 'Poor Cut Quality'
  AND NOT EXISTS (
    SELECT 1
    FROM qualification_questions qq
    WHERE qq.questionnaire_id = q.id
      AND qq.question_text = 'Have lens, mirrors, and material settings been cleaned/verified recently?'
);

-- Branching driven by "Has this machine ever cut properly" answer
INSERT INTO question_branches (question_id, answer_value, next_question_id, is_terminal)
SELECT
    q5.id,
    'No - it has never cut correctly',
    q6.id,
    0
FROM qualification_questionnaires qq
JOIN qualification_questions q5 ON q5.questionnaire_id = qq.id
    AND q5.question_text = 'Has this machine ever cut properly since you got it? (Yes - it worked great before / No - it has never cut correctly)'
JOIN qualification_questions q6 ON q6.questionnaire_id = qq.id
    AND q6.question_text = 'Have mirror alignment and focus calibration been checked yet?'
WHERE qq.name = 'New Machine - Never Worked Right'
  AND NOT EXISTS (
    SELECT 1
    FROM question_branches qb
    WHERE qb.question_id = q5.id
      AND qb.answer_value = 'No - it has never cut correctly'
);

INSERT INTO question_branches (question_id, answer_value, next_question_id, is_terminal)
SELECT
    q5.id,
    'Yes - it worked great before',
    NULL,
    1
FROM qualification_questionnaires qq
JOIN qualification_questions q5 ON q5.questionnaire_id = qq.id
    AND q5.question_text = 'Has this machine ever cut properly since you got it? (Yes - it worked great before / No - it has never cut correctly)'
WHERE qq.name = 'New Machine - Never Worked Right'
  AND NOT EXISTS (
    SELECT 1
    FROM question_branches qb
    WHERE qb.question_id = q5.id
      AND qb.answer_value = 'Yes - it worked great before'
);

INSERT INTO question_branches (question_id, answer_value, next_question_id, is_terminal)
SELECT
    q5.id,
    'Yes - it worked great before',
    q6.id,
    0
FROM qualification_questionnaires qq
JOIN qualification_questions q5 ON q5.questionnaire_id = qq.id
    AND q5.question_text = 'Has this machine ever cut properly since you got it? (Yes - it worked great before / No - it has never cut correctly)'
JOIN qualification_questions q6 ON q6.questionnaire_id = qq.id
    AND q6.question_text = 'What changed right before the issue started? (Power event, software/settings change, new material, no known change)'
WHERE qq.name = 'Machine Suddenly Stopped Working'
  AND NOT EXISTS (
    SELECT 1
    FROM question_branches qb
    WHERE qb.question_id = q5.id
      AND qb.answer_value = 'Yes - it worked great before'
);

INSERT INTO question_branches (question_id, answer_value, next_question_id, is_terminal)
SELECT
    q5.id,
    'No - it has never cut correctly',
    NULL,
    1
FROM qualification_questionnaires qq
JOIN qualification_questions q5 ON q5.questionnaire_id = qq.id
    AND q5.question_text = 'Has this machine ever cut properly since you got it? (Yes - it worked great before / No - it has never cut correctly)'
WHERE qq.name = 'Machine Suddenly Stopped Working'
  AND NOT EXISTS (
    SELECT 1
    FROM question_branches qb
    WHERE qb.question_id = q5.id
      AND qb.answer_value = 'No - it has never cut correctly'
);

INSERT INTO question_branches (question_id, answer_value, next_question_id, is_terminal)
SELECT
    q5.id,
    'Yes - it worked great before',
    q6.id,
    0
FROM qualification_questionnaires qq
JOIN qualification_questions q5 ON q5.questionnaire_id = qq.id
    AND q5.question_text = 'Has this machine ever cut properly since you got it? (Yes - it worked great before / No - it has never cut correctly)'
JOIN qualification_questions q6 ON q6.questionnaire_id = qq.id
    AND q6.question_text = 'Which issue best matches the cut quality problem? (Not cutting through, excessive charring, inconsistent depth, rough edges)'
WHERE qq.name = 'Poor Cut Quality'
  AND NOT EXISTS (
    SELECT 1
    FROM question_branches qb
    WHERE qb.question_id = q5.id
      AND qb.answer_value = 'Yes - it worked great before'
);

INSERT INTO question_branches (question_id, answer_value, next_question_id, is_terminal)
SELECT
    q5.id,
    'No - it has never cut correctly',
    q6.id,
    0
FROM qualification_questionnaires qq
JOIN qualification_questions q5 ON q5.questionnaire_id = qq.id
    AND q5.question_text = 'Has this machine ever cut properly since you got it? (Yes - it worked great before / No - it has never cut correctly)'
JOIN qualification_questions q6 ON q6.questionnaire_id = qq.id
    AND q6.question_text = 'Which issue best matches the cut quality problem? (Not cutting through, excessive charring, inconsistent depth, rough edges)'
WHERE qq.name = 'Poor Cut Quality'
  AND NOT EXISTS (
    SELECT 1
    FROM question_branches qb
    WHERE qb.question_id = q5.id
      AND qb.answer_value = 'No - it has never cut correctly'
);
