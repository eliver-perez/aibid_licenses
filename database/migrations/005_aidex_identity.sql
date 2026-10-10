UPDATE products
SET display_name='AIDEX', description='Aplicación de Indexación Digital de Expedientes', updated_at=UTC_TIMESTAMP(6)
WHERE product_id='gestor_documental'
  AND display_name='AIBID'
  AND description='Aplicación de Indexación de Bibliotecas Digitales';
