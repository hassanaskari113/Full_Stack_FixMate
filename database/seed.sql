-- FixMate sample data. Safe to run more than once (INSERT IGNORE skips duplicates).
-- Admins can add, edit and delete all of this from the admin panel.

INSERT IGNORE INTO service_categories (name) VALUES
 ('Plumbing'), ('Electrical'), ('Cleaning'), ('Carpentry'),
 ('AC & Appliance Repair'), ('Painting');

INSERT IGNORE INTO services (name, category_id) VALUES
 ('Leak repair',           (SELECT id FROM service_categories WHERE name = 'Plumbing')),
 ('Tap & mixer fitting',   (SELECT id FROM service_categories WHERE name = 'Plumbing')),
 ('Drain unblocking',      (SELECT id FROM service_categories WHERE name = 'Plumbing')),
 ('Wiring & rewiring',     (SELECT id FROM service_categories WHERE name = 'Electrical')),
 ('Fan & light fitting',   (SELECT id FROM service_categories WHERE name = 'Electrical')),
 ('Circuit breaker repair',(SELECT id FROM service_categories WHERE name = 'Electrical')),
 ('Home deep cleaning',    (SELECT id FROM service_categories WHERE name = 'Cleaning')),
 ('Sofa & carpet cleaning',(SELECT id FROM service_categories WHERE name = 'Cleaning')),
 ('Furniture repair',      (SELECT id FROM service_categories WHERE name = 'Carpentry')),
 ('Door & window fitting', (SELECT id FROM service_categories WHERE name = 'Carpentry')),
 ('AC servicing',          (SELECT id FROM service_categories WHERE name = 'AC & Appliance Repair')),
 ('Refrigerator repair',   (SELECT id FROM service_categories WHERE name = 'AC & Appliance Repair')),
 ('Interior painting',     (SELECT id FROM service_categories WHERE name = 'Painting')),
 ('Exterior painting',     (SELECT id FROM service_categories WHERE name = 'Painting'));

INSERT IGNORE INTO service_areas (name) VALUES
 ('Gulberg'), ('DHA'), ('Johar Town'), ('Model Town'),
 ('Bahria Town'), ('Wapda Town'), ('Iqbal Town'), ('Cantt');
