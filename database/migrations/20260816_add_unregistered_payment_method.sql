-- Permite importar pagos históricos reales cuando el método exacto no quedó registrado.
ALTER TABLE pagos
  MODIFY COLUMN metodo ENUM('EFECTIVO','TRANSFERENCIA','MERCADO_PAGO','NO_REGISTRADO') NOT NULL;
