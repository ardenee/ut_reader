INSERT INTO ue_federation_settings(setting_name, setting_value)
VALUES ('game_file_display_limit', '100')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
