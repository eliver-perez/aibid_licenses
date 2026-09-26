INSERT INTO products(product_id,display_name,description,state,created_at,updated_at)
VALUES ('gestor_documental','AIBID','Aplicación de Indexación de Bibliotecas Digitales','active',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6));
INSERT INTO product_capabilities(product_id,capability_key,display_name) VALUES
 ('gestor_documental','linked_libraries','Bibliotecas vinculadas'),
 ('gestor_documental','managed_libraries','Bibliotecas administradas'),
 ('gestor_documental','ocr','Reconocimiento de texto (OCR)'),
 ('gestor_documental','expedientes','Expedientes'),
 ('gestor_documental','review_workflow','Flujo de revisión');
INSERT INTO capability_dependencies VALUES ('gestor_documental','review_workflow','expedientes');
