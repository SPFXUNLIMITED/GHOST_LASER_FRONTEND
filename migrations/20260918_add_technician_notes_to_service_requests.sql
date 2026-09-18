ALTER TABLE service_requests
    ADD COLUMN technician_notes TEXT NULL
    AFTER problem_details;
