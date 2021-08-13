<?php


namespace Basilisk\SubResource;


use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\CssSelector\Exception\InternalErrorException;

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
            $this->newlyCreatedSubResourceInstances = [];
            $subResourceModel = $model->{$relationName}()->getModel();
            $subResourceValues = $requestData[$relationName] ?? [];
            foreach($subResourceValues as $subResourceValue):
                $this->newlyCreatedSubResourceInstances[] = (new SubResource())->storeSubResources($subResourceValue, $subResourceModel);
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
            $relationName = $this->processSubResourceConfigs($relationName, $subResourcesConfig);

            $this->newlyCreatedSubResourceInstances = [];
            $subResourceModel = $model->{$relationName}()->getModel();
            $relatedSubResourceIds = $currentResource->{$relationName}->pluck('id')->all();
            $subResourceNewValues = $requestData[$relationName] ?? [];
            foreach($subResourceNewValues as $subResourceValue)
                if (isset($subResourceValue['id'])):
                    $subResourceToBeUpdated = $subResourceModel->findOrFail($subResourceValue['id']);
                    if (($key = array_search($subResourceToBeUpdated->id, $relatedSubResourceIds)) !== false):
                        unset($relatedSubResourceIds[$key]);
                        (new SubResource())->updateSubResources($subResourceValue, $subResourceToBeUpdated, $subResourceModel);
                    else:
                        $this->updateSubResourceParent($currentResource, $subResourceToBeUpdated, $subResourceModel);
                    endif;
                else:
                    $this->newlyCreatedSubResourceInstances[] = (new SubResource())->updateSubResources($subResourceValue, null, $subResourceModel);
                endif;

            $this->removeSubResources($relatedSubResourceIds, $relationName, $currentResource, $subResourceModel);

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
    private function removeSubResources($relatedSubResourceIds, string $relationName, $parentModel, $subResourceModel): void
    {
        if (!empty($relatedSubResourceIds)):
            if ($parentModel->{$relationName}()->getProcessor() instanceof HasMany)
                $subResourceModel
                    ->whereIn('id', $relatedSubResourceIds)
                    ->update([$parentModel->{$relationName}()->getForeignKeyName() => null]);
            elseif ($parentModel->{$relationName}()->getProcessor() instanceof BelongsToMany)
                $parentModel->{$relationName}()->detach($relatedSubResourceIds);
        endif;

        if ($this->removeOrphanResources)
            $subResourceModel::destroy($relatedSubResourceIds);
    }

    /**
     * @param string $relationName
     * @param $currentResource
     * @param $subResourceToBeUpdated
     */
    private function updateSubResourceParent(string $relationName, $parentModel, $subResourceToBeUpdated): void
    {
        if ($parentModel->{$relationName}()->getProcessor() instanceof HasMany)
            $subResourceToBeUpdated->update([$parentModel->{$relationName}()->getForeignKeyName() => $parentModel->id]);
        elseif ($parentModel->{$relationName}()->getProcessor() instanceof BelongsToMany)
            $parentModel->{$relationName}()->attach($subResourceToBeUpdated->id);
    }
}
