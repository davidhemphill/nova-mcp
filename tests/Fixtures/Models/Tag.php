<?php

namespace Hemp\NovaMcp\Tests\Fixtures\Models;

use Hemp\NovaMcp\Tests\Fixtures\Factories\TagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    protected $guarded = [];

    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(Article::class);
    }

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }
}
