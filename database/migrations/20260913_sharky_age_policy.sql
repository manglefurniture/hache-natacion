INSERT INTO configuracion(clave,valor,descripcion)
VALUES ('sharky_edad_maxima','65','Edad máxima permitida para inscripciones automáticas de Sharky')
ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion);
