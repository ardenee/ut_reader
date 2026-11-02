using UnrealBuildTool;
public class UE427Oracle : ModuleRules {
 public UE427Oracle(ReadOnlyTargetRules Target):base(Target) {
  PCHUsage=PCHUsageMode.UseExplicitOrSharedPCHs;
  PrivateDependencyModuleNames.AddRange(new string[] {"Core","CoreUObject","Engine","UnrealEd"});
 }
}