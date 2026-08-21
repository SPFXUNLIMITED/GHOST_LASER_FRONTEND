CREATE TABLE IF NOT EXISTS qualification_questionnaires (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_qualification_questionnaires_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qualification_questions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    questionnaire_id INT UNSIGNED NOT NULL,
    question_text TEXT NOT NULL,
    question_type VARCHAR(50) NOT NULL DEFAULT 'text',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_qualification_questions_text (questionnaire_id, question_text(191)),
    INDEX idx_qualification_questions_questionnaire (questionnaire_id),
    CONSTRAINT fk_qualification_questions_questionnaire
        FOREIGN KEY (questionnaire_id) REFERENCES qualification_questionnaires(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS question_branches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id INT UNSIGNED NOT NULL,
    answer_value VARCHAR(255) NOT NULL,
    next_question_id INT UNSIGNED NULL,
    is_terminal TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uniq_question_branch_answer (question_id, answer_value),
    INDEX idx_question_branches_next_question (next_question_id),
    CONSTRAINT fk_question_branches_question
        FOREIGN KEY (question_id) REFERENCES qualification_questions(id) ON DELETE CASCADE,
    CONSTRAINT fk_question_branches_next_question
        FOREIGN KEY (next_question_id) REFERENCES qualification_questions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS qualification_responses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    questionnaire_id INT UNSIGNED NOT NULL,
    responses_json LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_qualification_responses_customer (customer_id),
    INDEX idx_qualification_responses_questionnaire (questionnaire_id),
    CONSTRAINT fk_qualification_responses_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_qualification_responses_questionnaire
        FOREIGN KEY (questionnaire_id) REFERENCES qualification_questionnaires(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
