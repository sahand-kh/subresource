# Laravel SubResource CRUD manager
## Introduction
SubResource is a Laravel package that create and update related models in a single request. Instead of creating each resource individually and accepting the overhead of managing their relationships, all you need to do is specify the relationships in the model, and **SubResource** does the rest.
Suppose you have house, room and furniture models . One solution would be to define all the furniture, rooms and the house separately and then define the relationship between them. The second solution is to define them all together, by sending a request to house endpoint, similar to the following:

    {
      ... //house properties
      rooms: [
        {
          ... //room1 properties
          furnitures: [
            {
              .../furniture1 properties
            },
            .
            .
            .
          ]
        },
        .
        .
        .
      ]
    }
SubResource takes the above request and define house, rooms and furnitures automatically.

## Getting started

**Subresource** is very easy to use. All that is needed is to add a public property to the model called **subResourcesConfigs** , which takes an array of child relationships. Back to our example Imagine the house model is as follow:

    class House extends Model
    {
      public function rooms()
      {
        return $this->hasMany(Room::class);
      }
    }

If you want to create and update rooms through House endpoint al you need to do is to add **subResourcesConfigs** property to the model as follow:
    
    class House extends Model
    {
      public $subResourcesConfigs = ['rooms'];
      
      public function rooms()
      {
        return $this->hasMany(Room::class);
      }
    } 

To also manage Furnitures through Room you have to also add name of the relation to Furniture to **subResourcesConfigs** property in the Room model. Now you can create/update Rooms and Furnitures  through House. Just make sure to set Content-type to **application/json** in the request and also put SubResources data in the JSON request body under the same key name as the relation. For example in above example Rooms data should be under rooms key in the JSON request because in **House** model the relation to **Room** model is named **rooms**.
To use SubResource you need to use SubResource Facade, which provides to method: **store** and **update**

    SubResource::store(\Illuminate\Http\Request $request,
    ParentModelClass::class);
    
    SubResource::update(\Illuminate\Http\Request $request, 
    ParentModelObjectWeTriedToUpdate);

## Update
To update a subresource you need to specify the resource id in the JSON request. For example imagine you want to update house with id "80" and also it rooms and each rooms furnitures, Thus you send a PUT/PATCH request to *"base_url/house/80"*, following is a sample request body:
  

      {
          ... //house properties
          rooms: [
            {
              id: x,
              ... //room1 properties
              furnitures: [
                {
                  id: x,
                  .../furniture1 properties
                },
                .
                .
                .
              ]
            },
            .
            .
            .
          ]
        }

If you do not provide the id for a subresource in the update request, it would consider as the new resource, therefore a new subresource would be created and associated with the parent resource.
Back to our example if a house has three rooms and in the update request body you provide only two existing rooms, the third one will be disassociated with the house. In other words the relationship between the parent and the child will be removed however if you wish to also remove the subresource you need to specify **removeOrphanResources** in the **subResourcesConfigs**:

    class House extends Model
    {
      public $subResourcesConfigs = [
      'rooms' => ['removeOrphanResources' => true]
      ];
      
      public function rooms()
      {
        return $this->hasMany(Room::class);
      }
    } 
In cases where specified child resource is already associated with another parent, if the relation is **Many-To-Many** then child resource will associated with the parent in addition to the previous parent, however, if relation ship is **One-To-One** or **Many-To-One** the child resource will be disassociated with the previous parent and associated with the new parent.
If you only want to update the parent and Leave the childs untouched, you can remove the subresource related keys in the JSON request.
## Supported Relation Types
Currently **SubResource** Package supports following relation types:

 - **Many-To-Many**
 - **Many-To-One**
 - **One-To-One**

Soon, support for polymorphic relations will also be added to the package.