-- SK Hospital is a standalone organization, not a seventh IHTM department center.
INSERT INTO organizations(name,short_name,type,country,city,state,contact_email,active)
SELECT 'SK Hospital','SKH','standalone','India','Sikar','Rajasthan',NULL,1
WHERE NOT EXISTS (SELECT 1 FROM organizations WHERE short_name='SKH');

UPDATE organizations
SET name='SK Hospital',type='standalone',country='India',state='Rajasthan',city='Sikar',active=1
WHERE short_name='SKH';

UPDATE blood_centers c
JOIN organizations o ON o.short_name='SKH'
SET c.org_id=o.id,c.name='SK Hospital Blood Center',c.country='India',c.state='Rajasthan',
    c.city='Sikar',c.location_detail='SK Hospital, Sikar',c.phenotyping_capable=1,c.active=1
WHERE c.code='SK';

INSERT INTO blood_centers(org_id,name,code,country,state,city,location_detail,phenotyping_capable,active)
SELECT o.id,'SK Hospital Blood Center','SK','India','Rajasthan','Sikar','SK Hospital, Sikar',1,1
FROM organizations o
WHERE o.short_name='SKH'
  AND NOT EXISTS (
    SELECT 1 FROM blood_centers c
    WHERE c.org_id=o.id AND c.code='SK'
  );
