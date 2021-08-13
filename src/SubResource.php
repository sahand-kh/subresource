<?php


namespace Basilisk\SubResource;


use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubResource
{
    private $newlyCreatedSubResourceInstances;
    private $removeOrphanResources;

    public function __construct()
    {
        $this->removeOrphanResources = false;
    }

    public function store($request, $modelClassName)
    {
        try {
            DB::beginTransaction();
            $this->storeSubResources($request, $modelClassName);
            DB::commit();
        } catch(\Exception $exception) {
            DB::rollBack();
            throw $exception;
        }
    }


    public function update($request, $currentResource)
    {
        try {
            DB::beginTransaction();
            $this->updateSubResources($request, $currentResource, get_class($currentResource));
            DB::commit();
        } catch(\Exception $exception) {
            DB::rollBack();
            throw $exception;
        }
    }

    private function storeSubResources($request, $resourceModelName)
    {
        $requestData = ($request instanceof Request) ? $request->all() : $request;
        $model =  new $resourceModelName;
        $currentResource = $model::create($requestData);
        foreach ($model->subResourcesConfigs ?? [] as $relationName => $subResourcesConfig):
            $relationName = $this->processSubResourceConfigs($relationName, $subResourcesConfig);
            $relationType = $model->{$relationName}()->getProcessor();
            $this->newlyCreatedSubResourceInstances = [];
            $subResourceModel = $model->{$relationName}()->getModel();
            $subResourceValues = $requestData[$relationName] ?? [];
            foreach($subResourceValues as $subResourceValue):
                $this->newlyCreatedSubResourceInstances[] = (new SubResource())->storeSubResources($subResourceValue, $subResourceModel);
                if ($relationType instanceof HasOne || $relationType instanceof BelongsTo)
                    break;
            endforeach;

            $currentResource->{$relationName}()->saveMany($this->newlyCreatedSubResourceInstances);
        endforeach;

        return $currentResource;
    }


    private function updateSubResources($request, $currentResource, $resourceModelName)
    {
        $requestData = ($request instanceof Request) ? $request->all() : $request;
        $model =  new $resourceModelName;
        $currentResource = $model::updateOrCreate(
            ['id' => $currentResource->id ?? 0],
            $requestData
        );

        foreach ($model->subResourcesConfigs ?? [] as $relationName => $subResourcesConfig):
            $this->newlyCreatedSubResourceInstances = [];
            $relationName = $this->processSubResourceConfigs($relationName, $subResourcesConfig);
            $relationType = $model->{$relationName}()->getProcessor();
            $subResourceModel = $model->{$relationName}()->getModel();
            $relatedSubResourceIds = $currentResource->{$relationName}->pluck('id')->all();
            $subResourceNewValues = $requestData[$relationName] ?? [];
            if (!empty($subResourceNewValues) && ($relationType instanceof HasOne || $relationType instanceof BelongsTo))
                $subResourceNewValues[] = $subResourceNewValues; //sdgsdgsdgsdgsdgsdg
            foreach($subResourceNewValues as $subResourceValue)
                if (isset($subResourceValue['id'])):
                    $subResourceToBeUpdated = $subResourceModel->findOrFail($subResourceValue['id']);
                    if (($key = array_search($subResourceToBeUpdated->id, $relatedSubResourceIds)) !== false):
                        unset($relatedSubResourceIds[$key]);
                        (new SubResource())->updateSubResources($subResourceValue, $subResourceToBeUpdated, $subResourceModel);
                    else:
                        $this->updateSubResourceParent($relationName, $relationType, $currentResource, $subResourceModel);
                    endif;
                else:
                    $this->newlyCreatedSubResourceInstances[] = (new SubResource())->updateSubResources($subResourceValue, null, $subResourceModel);
                endif;

            $this->removeSubResources($relatedSubResourceIds, $relationName, $relationType, $currentResource, $subResourceModel);

            $currentResource->{$relationName}()->saveMany($this->newlyCreatedSubResourceInstances);
        endforeach;

        return $currentResource;
    }

    /**
     * @param $relationName
     * @param $subResourcesConfig
     */
    private function processSubResourceConfigs($relationName, $subResourcesConfig): string
    {
        if (is_array($subResourcesConfig))
            if (
                isset($subResourcesConfig['removeOrphanResources']) &&
                is_bool($subResourcesConfig['removeOrphanResources'])
            ):
                $this->removeOrphanResources = $subResourcesConfig['removeOrphanResources'];
            endif;
        else
            $relationName = $subResourcesConfig;

            return $relationName;
    }

    /**
     * @param $relatedSubResourceIds
     * @param string $relationName
     * @param $parentModel
     * @param $subResourceModel
     */
    private function removeSubResources($relatedSubResourceIds, string $relationName, $relationType, $parentModel, $subResourceModel): void
    {
        if (!empty($relatedSubResourceIds)):
            if ($relationType instanceof HasMany || $relationType instanceof HasOne)
                $subResourceModel
                    ->whereIn('id', $relatedSubResourceIds)
                    ->update([$parentModel->{$relationName}()->getForeignKeyName() => null]);
            elseif ($relationType instanceof BelongsTo)
                $parentModel->update([$parentModel->{$relationName}()->getForeignKeyName() => null]);
            elseif ($relationType instanceof BelongsToMany)
                $parentModel->{$relationName}()->detach($relatedSubResourceIds);
        endif;

        if ($this->removeOrphanResources)
            $subResourceModel::destroy($relatedSubResourceIds);
    }

    /**
     * @param string $relationName
     * @param $relationType
     * @param $parentModel
     * @param $subResourceModel
     */
    private function updateSubResourceParent(string $relationName, $relationType, $parentModel, $subResourceModel): void
    {
        if ($relationType instanceof HasMany || $relationType instanceof HasOne)
            $subResourceModel->update([$parentModel->{$relationName}()->getForeignKeyName() => $parentModel->id]);
        elseif ($relationType instanceof BelongsTo)
            $parentModel->update([$parentModel->{$relationName}()->getForeignKeyName() => $subResourceModel->id]);
        elseif ($relationType instanceof BelongsToMany)
            $parentModel->{$relationName}()->attach($subResourceModel->id);
    }
}