INSERT INTO organizations(id,name,short_name,type,country,city,state,contact_email,active) VALUES
(1,'Department of Immunohematology & Transfusion Medicine','IHTM','department','India','Jaipur','Rajasthan','ihtm@smshospital.in',1),
(2,'SK Hospital','SKH','standalone','India','Sikar','Rajasthan',NULL,1);
INSERT INTO blood_centers(id,org_id,name,code,country,state,city,location_detail,phenotyping_capable) VALUES
(1,1,'SMS Blood Center','SMS','India','Rajasthan','Jaipur','Main Blood Bank, Ground Floor, SMS Hospital',1),
(2,1,'Trauma Blood Center','TRAUMA','India','Rajasthan','Jaipur','Trauma Centre, Emergency Block, SMS Hospital',0),
(3,1,'JKLoan Blood Center','JKLOAN','India','Rajasthan','Jaipur','JK Lon Building, SMS Hospital',1),
(4,1,'Mahila Blood Center','MAHILA','India','Rajasthan','Jaipur','Mahila Chikitsalay, SMS Hospital Campus',0),
(5,1,'Zenana Blood Center','ZENANA','India','Rajasthan','Jaipur','Zenana Hospital, SMS Hospital Campus',1),
(6,1,'SCI Blood Center','SCI','India','Rajasthan','Jaipur','Sawai Choudhary Indira Gandhi Hospital (SCI), Jaipur',0),
(7,2,'SK Hospital Blood Center','SK','India','Rajasthan','Sikar','SK Hospital, Sikar',1);
INSERT INTO settings(key_name,value,description) VALUES('platform_name','ThalVital','Platform display name'),('default_language','en','Default UI language'),('target_hb_min','7.0','Target pre-transfusion Hb floor'),('target_hb_max','10.0','Target post-transfusion Hb'),('ferritin_high','2500','High ferritin threshold'),('due_soon_days','7','Days ahead to flag due');
INSERT INTO blood_groups(code,abo,rh) VALUES('A+','A','Pos'),('A-','A','Neg'),('B+','B','Pos'),('B-','B','Neg'),('AB+','AB','Pos'),('AB-','AB','Neg'),('O+','O','Pos'),('O-','O','Neg');
INSERT INTO antigen_systems(system_name,antigens) VALUES('Rh','D,C,c,E,e'),('Kell','K,k'),('Kidd','Jka,Jkb'),('Duffy','Fya,Fyb'),('MNS','M,N,S,s');
INSERT INTO known_antibodies(name,`system`,clinical_significance) VALUES('anti-D','Rh','High'),('anti-C','Rh','High'),('anti-c','Rh','High'),('anti-E','Rh','High'),('anti-e','Rh','High'),('anti-K','Kell','High'),('anti-k','Kell','High'),('anti-Jka','Kidd','High'),('anti-Jkb','Kidd','High'),('anti-Fya','Duffy','High'),('anti-Fyb','Duffy','Moderate'),('anti-M','MNS','Low'),('anti-N','MNS','Low'),('anti-S','MNS','High'),('anti-s','MNS','High');
-- Synthetic development inventory only. It is deliberately spread across a
-- subset of centers so availability searches exercise both non-zero and zero results.
-- Structured three-state antigens: 1 = present, 0 = tested-negative, NULL = NOT TESTED.
-- NULL must never match as antigen-negative. Rh-only centers (2 Trauma, 4 Mahila, 6 SCI)
-- do not run the K screen, so their bags carry antigen_K = NULL. DEMO-B-NEG-002 is a
-- fully-untyped bag (all NULL). phenotype_string is display-only, derived from the columns.
INSERT INTO bags(bag_number,center_id,year,donation_type,blood_group,antigen_C,antigen_c_lower,antigen_E,antigen_e_lower,antigen_K,phenotype_string,product,volume_ml,collection_date,expiry_date,status,notes) VALUES
('DEMO-A-POS-001',1,YEAR(CURDATE()),'voluntary','A+',1,1,0,1,0,'C+ c+ E- e+ K-','PRC',250,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('DEMO-A-POS-002',3,YEAR(CURDATE()),'voluntary','A+',1,0,0,1,0,'C+ c- E- e+ K-','PRC',300,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('DEMO-A-NEG-001',2,YEAR(CURDATE()),'voluntary','A-',1,1,0,1,NULL,'C+ c+ E- e+','PRC',250,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('DEMO-A-NEG-002',5,YEAR(CURDATE()),'replacement','A-',0,1,0,1,0,'C- c+ E- e+ K-','PRC',250,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('DEMO-B-POS-001',1,YEAR(CURDATE()),'voluntary','B+',1,1,0,1,NULL,'C+ c+ E- e+','PRC',250,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('DEMO-B-POS-002',3,YEAR(CURDATE()),'voluntary','B+',0,1,1,0,1,'C- c+ E+ e- K+','PRC',300,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('DEMO-B-NEG-001',4,YEAR(CURDATE()),'voluntary','B-',1,1,0,1,NULL,'C+ c+ E- e+','PRC',250,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('DEMO-B-NEG-002',6,YEAR(CURDATE()),'replacement','B-',NULL,NULL,NULL,NULL,NULL,NULL,'PRC',250,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('DEMO-AB-POS-001',1,YEAR(CURDATE()),'voluntary','AB+',1,1,0,1,0,'C+ c+ E- e+ K-','PRC',250,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('DEMO-AB-POS-002',5,YEAR(CURDATE()),'voluntary','AB+',1,1,0,1,0,'C+ c+ E- e+ K-','PRC',300,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('DEMO-AB-NEG-001',3,YEAR(CURDATE()),'voluntary','AB-',1,1,0,1,1,'C+ c+ E- e+ K+','PRC',250,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('DEMO-AB-NEG-002',6,YEAR(CURDATE()),'replacement','AB-',0,1,0,1,NULL,'C- c+ E- e+','PRC',250,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('DEMO-O-POS-001',2,YEAR(CURDATE()),'voluntary','O+',1,1,0,1,NULL,'C+ c+ E- e+','PRC',250,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('DEMO-O-POS-002',5,YEAR(CURDATE()),'voluntary','O+',1,0,0,1,0,'C+ c- E- e+ K-','PRC',300,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('DEMO-O-NEG-001',1,YEAR(CURDATE()),'voluntary','O-',1,1,0,1,0,'C+ c+ E- e+ K-','PRC',250,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('DEMO-O-NEG-002',4,YEAR(CURDATE()),'replacement','O-',0,1,1,0,NULL,'C- c+ E+ e-','PRC',250,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 42 DAY),'available','Synthetic demo bag'),
('LOCAL-SMS-A-POS-01',1,YEAR(CURDATE()),'voluntary','A+',1,1,0,1,0,'C+ c+ E- e+ K-','PRC',250,DATE_SUB(CURDATE(),INTERVAL 7 DAY),DATE_ADD(CURDATE(),INTERVAL 35 DAY),'available','Synthetic local SMS test bag'),
('LOCAL-SMS-A-POS-02',1,YEAR(CURDATE()),'voluntary','A+',1,0,1,0,1,'C+ c- E+ e- K+','PRC',250,DATE_SUB(CURDATE(),INTERVAL 7 DAY),DATE_ADD(CURDATE(),INTERVAL 35 DAY),'available','Synthetic local SMS test bag'),
('LOCAL-SK-A-POS-01',7,YEAR(CURDATE()),'voluntary','A+',1,1,0,1,0,'C+ c+ E- e+ K-','PRC',250,DATE_SUB(CURDATE(),INTERVAL 7 DAY),DATE_ADD(CURDATE(),INTERVAL 35 DAY),'available','Synthetic local SK test bag'),
('LOCAL-SK-A-POS-02',7,YEAR(CURDATE()),'voluntary','A+',0,1,1,0,1,'C- c+ E+ e- K+','PRC',250,DATE_SUB(CURDATE(),INTERVAL 7 DAY),DATE_ADD(CURDATE(),INTERVAL 35 DAY),'available','Synthetic local SK test bag'),
('LOCAL-SMS-A-POS-03',1,YEAR(CURDATE()),'voluntary','A+',0,1,0,1,0,'C- c+ E- e+ K-','PRC',250,DATE_SUB(CURDATE(),INTERVAL 7 DAY),DATE_ADD(CURDATE(),INTERVAL 35 DAY),'available','Synthetic local SMS test bag'),
('LOCAL-SK-A-POS-03',7,YEAR(CURDATE()),'voluntary','A+',1,0,0,1,0,'C+ c- E- e+ K-','PRC',250,DATE_SUB(CURDATE(),INTERVAL 7 DAY),DATE_ADD(CURDATE(),INTERVAL 35 DAY),'available','Synthetic local SK test bag');
