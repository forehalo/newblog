<?php

namespace App\Repositories;

use DB;
use Parsedown;
use App\Models\Post;
use App\Models\Category;
use App\Models\Tag;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class PostRepository
{

    /**
     * Get all posts order by publish date.
     *
     * @param int $limit
     * @param boolean $manage
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function all($limit = 8, $manage = false)
    {
        $prepare = Post::orderBy('created_at', 'desc');

        if ($manage) {
            $prepare->manage();
        } else {
            $prepare->list()->with('tags');
        }

        return $prepare->paginate((int)$limit)->toArray();
    }

    /**
     * Get record(s) by given column.
     *
     * @param  string $column
     * @param  mixed $value
     * @param  boolean $collected
     * @return \Illuminate\Support\Collection|\App\Models\Post
     */
    public function getByColumn($column, $value, $collected = false)
    {
        $prepare = Post::where($column, $value)->show();
        return $collected ? $prepare->get() : $prepare->with(['tags', 'category'])->first();
    }


    /**
     * Like the post.
     *
     * @param int $postID
     */
    public function likePost($postID)
    {
        return Post::where('id', $postID)->increment('favorite_count');
    }

    /**
     * Group all dates with posts published, group by year/month.
     *
     * @return array
     */
    public function groupDates()
    {
        $dates = Post::select('created_at')->where('published', true)->orderBy('created_at', 'desc')->get()->pluck('created_at');

        $group = [];
        foreach ($dates as $date) {
            $year = $date->year;
            $month = $date->month;
            if (! array_key_exists($date->year, $group)) {
                $group[$year] = ['year' => $year, 'months' => []];
            }

            if (array_search($month, $group[$year]['months']) === false) {
                array_push($group[$year]['months'], $month);
            }
        }
        return array_values($group);
    }

    /**
     * Get articles posted in year-month.
     *
     * @param string $yearMonth
     */
    public function getPostsByYearMonth($yearMonth)
    {
        $date = explode('-', $yearMonth);
        $from = Carbon::create($date[0], $date[1], 1, 0, 0, 0);
        $to = Carbon::create($date[0], (int)$date[1] + 1, 0, 23, 59, 59);
        return Post::select('id', 'title', 'slug')->whereBetween('created_at', [$from, $to])->get();
    }

    /**
     * Get post count.
     *
     * @return int
     */
    public function getPostCount()
    {
        return Post::count();
    }

    /**
     * Toggle post published state.
     *
     * @param $id
     * @return boolean
     */
    public function togglePublish($id)
    {
        $post = Post::where('id', $id)->first();
        return $post->update(['published' => !$post->published]);
    }

    /**
     * Soft delete post.
     *
     * @param $id
     * @return int
     */
    public function delete($id)
    {
        return Post::destroy($id);
    }

    /**
     * Get original post data.
     * @param  int $id
     * @return array|boolean
     */
    public function origin($id)
    {
        $post = Post::where('id', $id)
            ->with('category', 'tags')
            ->first(['id', 'title', 'slug', 'summary', 'category_id', 'origin', 'published']);

        if (is_null($post)) {
            return false;
        }

        $post = $post->toArray();
        $post['category'] = $post['category']['name'];
        $post['tags'] = array_column($post['tags'], 'name');
        return $post;
    }


    public function store($inputs)
    {
        $post = new Post();

        return $this->save($post, $inputs);
    }

    public function update($id, $inputs)
    {
        $post = Post::where('id', $id)->first();
        if (is_null($post)) {
            return false;
        }

        return $this->save($post, $inputs);
    }

    public function save(Post $post, $inputs)
    {
        $parser = new Parsedown();

        $post->title = $inputs['title'];
        $post->slug = $inputs['slug'];
        $post->summary = $inputs['summary'];
        $post->origin = $inputs['origin'];
        $post->body = $parser->parse($inputs['origin']);
        // Begin transaction
        return DB::transaction(function () use ($post, $inputs) {
            $categorySync = $this->syncCategory($post, $inputs['category']);
            $tagsSync = $this->syncTags($post, $inputs['tags']);
            $postSaved = $post->save();

            return $postSaved && $categorySync && $tagsSync;
        });
    }

    public function syncCategory(Post $post, $categoryName)
    {
        $category = Category::where('name', $categoryName)->first();
        if (is_null($category)) {
            $category = Category::create(['name' => $categoryName]);
        }
        return $post->category()->associate($category);
    }
    
    public function syncTags(Post $post, $tags)
    {
        $tagCollection = Tag::whereIn('name', $tags)->get(['name']);
        // Insert non-existent tags.
        $uncreatedTags = array_diff($tags, array_values($tagCollection->pluck('name')->toArray()));
        $now = time();
        Tag::insert(
            array_map(function ($item) use ($now){
                return ['name' => $item, 'created_at' => $now, 'updated_at' => $now];
            }, $uncreatedTags)
        );

        $ids = Tag::whereIn('name', $tags)->get(['id'])->pluck('id')->toArray();

        return $post->tags()->sync($ids);
    }
}
