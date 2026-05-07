<?php namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Service extends Model {
    protected $fillable = ['name','description','category_id','api_provider_id','api_service_id','rate','min','max','status','type'];
    public function category()    { return $this->belongsTo(Category::class); }
    public function apiProvider() { return $this->belongsTo(ApiProvider::class); }
    public function orders()      { return $this->hasMany(Order::class); }
}

class Category extends Model {
    protected $fillable = ['name','icon','color','status'];
    public function services() { return $this->hasMany(Service::class); }
}

class ApiProvider extends Model {
    protected $fillable = ['name','url','api_key','status','percentage_increase'];
    public function services() { return $this->hasMany(Service::class); }
}

class Transaction extends Model {
    protected $fillable = ['user_id','amount','type','description','status','reference'];
    protected $casts    = ['amount' => 'float'];
    public function user() { return $this->belongsTo(User::class); }
}

class Ticket extends Model {
    protected $fillable = ['user_id','subject','message','category','order_id','status'];
    public function user()     { return $this->belongsTo(User::class); }
    public function messages() { return $this->hasMany(TicketMessage::class)->orderBy('created_at'); }
}

class TicketMessage extends Model {
    protected $fillable = ['ticket_id','user_id','message','is_admin'];
    protected $casts    = ['is_admin' => 'boolean'];
    public function ticket() { return $this->belongsTo(Ticket::class); }
    public function user()   { return $this->belongsTo(User::class); }
}
