UPDATE ue_game_profiles p
JOIN ue_games g ON g.profile_id = p.id
SET p.allowed_extensions_json = JSON_ARRAY('u','un2','ut2','utx','usx','ukx','uax','umx','upx','ugx','con')
WHERE p.engine_key = 'UE2'
  AND g.slug IN ('unreal2','ut2003','ut2004');

UPDATE ue_game_profiles
SET allowed_extensions_json = JSON_ARRAY('u','un2','ut2','utx','usx','ukx','uax','umx','upx','ugx','con')
WHERE engine_key = 'UE2'
  AND profile_name IN ('Unreal II','Unreal Tournament 2003','Unreal Tournament 2004');
