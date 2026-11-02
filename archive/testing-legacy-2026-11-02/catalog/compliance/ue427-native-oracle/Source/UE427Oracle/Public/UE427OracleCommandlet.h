#pragma once
#include "CoreMinimal.h"
#include "Commandlets/Commandlet.h"
#include "UE427OracleCommandlet.generated.h"
UCLASS()
class UE427ORACLE_API UUE427OracleCommandlet : public UCommandlet {
 GENERATED_BODY()
public:
 UUE427OracleCommandlet();
 virtual int32 Main(const FString& Params) override;
};