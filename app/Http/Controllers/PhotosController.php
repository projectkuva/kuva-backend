<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Validator;
use Illuminate\Http\Request;
use Auth;
use App\Models\User;
use App\Models\Photo;
use App\Models\Comment;
use App\Models\Report;
use App\Models\Like;
Use App\Models\Role;
use Image;
use JWTAuth;
use Mail;
use Log;

//Photo Controller
class PhotosController extends Controller
{

    /**
     * Create a photo
     *
     * @param  Request  $request
     * @return Response
     */
    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'caption' => 'required|string|max:255',
            'photo' => 'required|image',
        ]);

        if ($validator->fails()) {
            return $validator->errors()->all();
        }

        $photo = new Photo;
        $photo->user_id = Auth::user()->id;
        $photo->caption = $request->caption;
        $photo->lat = $request->lat;
        $photo->lng = $request->lng;
        $photo->save();

        $image = $request->file('photo');
        $destinationPath = storage_path('app/public') . '/uploads';
        $name = $photo->id . '.jpg';
        if(!$image->move($destinationPath, $name)) {
            return ['message' => 'Error saving the file.', 'code' => 400];
        }
        $img = Image::make($destinationPath . '/' . $name)->encode('jpg', 75)->save();
        return ['message' => 'success', 'photo_id' => $photo->id];
    }

    public function getPhoto(Request $request, Photo $photo) {
    	$photoDetails = Photo::where('id',$photo->id)->with('comments.user')->with('likes.user')->with('user')->get()->toArray();
    	try {
    		$user = JWTAuth::parseToken()->authenticate();
    		$photoDetails['user_liked'] = 0;
    		$like = Like::where('user_id', $user->id)->where('photo_id', $photo->id)->first();
    		if($like !== null && $like['liked'] == 1) {
    			$photoDetails['user_liked'] = 1;
    		}
    	} catch (\Exception $e) {
    		return ['message' => 'invalid_photo'];
    	}
      	return $photoDetails;
    }

    public function delete(Request $request, Photo $photo) {
        if($photo->user_id != Auth::user()->id) {
            return ['message' => 'authentication'];
        }
        $photo->delete();
        return ['message' => 'success'];
    }

    public function comment(Request $request, Photo $photo) {
        $validator = Validator::make($request->all(), [
            'text' => 'required|between:1,200',
        ]);

        if ($validator->fails()) {
            return $validator->errors()->all();
        }

        $comment = new Comment;
        $comment->text = $request->text;
        $comment->photo_id = $photo->id;
        $comment->user_id = Auth::user()->id;
        $comment->save();
        return ['message' => 'success'];
    }

    public function like(Request $request, Photo $photo) {
        $validator = Validator::make($request->all(), [
            'liked' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return $validator->errors()->all();
        }

        $like = Like::firstOrNew(['user_id' => Auth::user()->id, 'photo_id' => $photo->id]);
        $like->liked = $request->liked;
        $like->photo_id = $photo->id;
        $like->user_id = Auth::user()->id;
        $like->save();
        return ['message' => 'success'];
    }

    public function reportPhoto(Request $request, Photo $photo) {
        $validator = Validator::make($request->all(), [
            'message' => 'required',
        ]);

        if ($validator->fails()) {
            return $validator->errors()->all();
        }

        //Only generate token/email on the first report
        if(count(Report::where('photo_id',$photo->id)->first()) == 0) {
          $report = new Report;
          $report->message = $request->message;
          $report->token = str_random(50);
          $report->photo_id = $photo->id;
          $report->save();

          $adminRole = Role::where('name','admin')->first();
          if(count($adminRole) > 0) {
            $adminEmails = $adminRole->users()->value('email');
            Log::debug('Sending report to '.$adminEmails);
            if(count($adminEmails) > 0) {
              //Send Emails
              Mail::queue('emails.report', ['photo' => $photo ,'reportMessage' => $report->message,'token' => $report->token], function ($email) use($adminEmails) {
                  $email->from('noreply@kuva.com');
                  $email->subject("Kuva Photo Report");
                  $email->to($adminEmails);
              });
            } else {
              Log::debug('No admins registered. No emails sent.');
            }
          }
        }

        return ['message' => 'success'];
    }

    //Delete a photo using the admin's token
    public function confirmReport(Request $request) {

      if(!isset($request->token)) {
        return ['message' => 'token_required'];
      }

      //Find the report
      $report = Report::where('token',$request->token)->first();
      if(count($report) == 0) {
        return ['message' => 'invalid_token'];
      }

      //Remove the related photo & this report
      $report->photo->delete();
      $report->delete();

      return ['message' => 'success'];
    }

    //Get a feed of recent activity on this user's account
    public function getActivityFeed(Request $request) {
      $feedData = Photo::where('user_id',Auth::id())->with('likes.user')->with('comments.user')->get();
      $combinedData = array();
      foreach($feedData as $currentPhoto) {
        foreach($currentPhoto->likes as $like) {
          $like->type = "like";
          array_push($combinedData,$like);
        }
        foreach($currentPhoto->comments as $comment) {
          $comment->type = "comment";
          array_push($combinedData,$comment);
        }
      }

      //Sort Comments & Likes by timestamp, starting with most recent
      usort($combinedData,function($a,$b) {
        return $a->created_at < $b->created_at;
      });
      $data = array('data' => $combinedData);
      return $data;
    }

    public function feed(Request $request) {
      $validator = Validator::make($request->all(), [
          'lat' => 'required|numeric',
          'lng' => 'required|numeric',
          'popularity' => 'boolean',
      ]);

      if ($validator->fails()) {
          return $validator->errors()->all();
      }

      //TODO- Validate lat/lng more carefully since they go into a raw query
      $photos = (Photo::getByDistance2($request->lat, $request->lng, 200));
      foreach($photos as $photo) {
        $photo->numLikes = $photo->numLikes;
        $photo->numComments = $photo->numComments;
        $photo->likes = $photo->likes;
        $photo->comments = $photo->comments;
        foreach($photo->comments as $comment) {
            $comment->user = User::where('id', $comment->user_id)->get(['name']);
        }
      }
      
      $photos = $photos->toArray();

      if($request->popularity) {
      	usort($photos, function($a,$b) {
        	return $a['numLikes'] < $b['numLikes'];
      	});
      }
      
      //TODO- Filter these by popularity/time/etc
      return $photos;
    }

 	public function getProfile(Request $request, User $user) {
 		$photos = Photo::where('user_id', $user->id)->get();
        return ['message' => 'success', 'name' => $user->name, 'photos' => $photos, 'profile_photo' => $user->profile_photo];
 	}

    public function userPhotos() {
        $photos = Photo::where('user_id', Auth::user()->id)->get();
        return ['message' => 'success', 'photos' => $photos];
    }

    /**
     * Create a photo
     *
     * @param  Request  $request
     * @return Response
     */
    public function createProfilePhoto(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'photo' => 'required|image',
        ]);

        if ($validator->fails()) {
            return $validator->errors()->all();
        }

        $name = str_random(5) . '.jpg';
        $image = $request->file('photo');
        $destinationPath = storage_path('app/public') . '/uploads/profile';
        if(!$image->move($destinationPath, $name)) {
            return ['message' => 'Error saving the file.', 'code' => 400];
        }
        $img = Image::make($destinationPath . '/' . $name)->encode('jpg', 75)->save();
        Auth::user()->profile_photo = $name;
        Auth::user()->save();
        return ['message' => 'success', 'photo' => $name];
    }
}
