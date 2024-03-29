-- This file is automatically generated using maintenance/generateSchemaSql.php.
-- Source: extensions/AutoModerator/sql/tables.json
-- Do not modify this file directly.
-- See https://www.mediawiki.org/wiki/Manual:Schema_changes
CREATE TABLE /*_*/automoderator_rev_score (
  amrs_id BIGINT UNSIGNED AUTO_INCREMENT NOT NULL,
  amrs_timestamp BINARY(14) NOT NULL,
  amrs_rev INT UNSIGNED NOT NULL,
  amrs_model SMALLINT NOT NULL,
  amrs_bucket TINYINT(16) NOT NULL,
  amrs_prob NUMERIC(3, 3) NOT NULL,
  amrs_pred TINYINT(1) NOT NULL,
  amrs_status TINYINT(16) NOT NULL,
  INDEX amrs_rev_model_bucket (
    amrs_rev, amrs_model, amrs_bucket
  ),
  INDEX amrs_model_bucket_prob (
    amrs_model, amrs_bucket, amrs_prob
  ),
  UNIQUE INDEX amrs_id_rev_status (amrs_id, amrs_rev, amrs_status),
  PRIMARY KEY(amrs_id)
) /*$wgDBTableOptions*/;


CREATE TABLE /*_*/automoderator_model (
  amm_id SMALLINT UNSIGNED AUTO_INCREMENT NOT NULL,
  amm_name VARCHAR(32) NOT NULL,
  amm_version VARCHAR(32) NOT NULL,
  amm_is_current TINYINT(1) NOT NULL,
  INDEX amrm_model_status (amm_name, amm_is_current),
  UNIQUE INDEX amrm_version (amm_name, amm_version),
  PRIMARY KEY(amm_id)
) /*$wgDBTableOptions*/;
