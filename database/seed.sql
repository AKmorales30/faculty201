USE faculty201_repository;

-- Default password for ALL seeded accounts below is:  Password123
-- (bcrypt hash below is real and verified against PHP's password_verify())
INSERT INTO users (role, full_name, email, password_hash, employment_type, employment_status, date_engaged) VALUES
('admin',         'Chrystal Jewelle Arnesto', 'admin@ccs.udm.edu.ph',        '$2b$10$ucYlHMTarCTqWABtKlMzIO4s72/pLe11WRMyUJeT04GP1SyAxeqMa', NULL, NULL, NULL),
('program_chair', 'Engr. Rosario Villanueva', 'programchair@ccs.udm.edu.ph', '$2b$10$ucYlHMTarCTqWABtKlMzIO4s72/pLe11WRMyUJeT04GP1SyAxeqMa', NULL, NULL, NULL),
('dean',          'Dr. Fernando Del Rosario', 'dean@ccs.udm.edu.ph',         '$2b$10$ucYlHMTarCTqWABtKlMzIO4s72/pLe11WRMyUJeT04GP1SyAxeqMa', 'full_time', 'active', '2015-06-01'),
('faculty',       'Adrian Kyle Morales',      'amorales@ccs.udm.edu.ph',     '$2b$10$ucYlHMTarCTqWABtKlMzIO4s72/pLe11WRMyUJeT04GP1SyAxeqMa', 'full_time', 'active', '2019-08-15'),
('faculty',       'Fionna Yvonne Austria',    'faustria@ccs.udm.edu.ph',     '$2b$10$ucYlHMTarCTqWABtKlMzIO4s72/pLe11WRMyUJeT04GP1SyAxeqMa', 'part_time', 'paused', '2021-01-10'),
('faculty',       'John Michael Santos',      'jsantos@ccs.udm.edu.ph',      '$2b$10$ucYlHMTarCTqWABtKlMzIO4s72/pLe11WRMyUJeT04GP1SyAxeqMa', 'part_time', 'active', '2022-06-20');

INSERT INTO employment_history (faculty_id, event_type, event_date, remarks) VALUES
(4, 'engaged', '2019-08-15', 'Initial engagement as full-time faculty'),
(5, 'engaged', '2021-01-10', 'Initial engagement as part-time faculty'),
(5, 'paused',  '2023-06-01', 'Took a leave of absence'),
(6, 'engaged', '2022-06-20', 'Initial engagement as part-time faculty');

-- NOTE: Run generate_password_hash.php (included) to produce a real bcrypt
-- hash for "Password123" on your own machine/PHP version and replace the
-- password_hash values above before using this seed data. PHP's
-- password_hash() output differs slightly by build, and the placeholder
-- above is illustrative only.
