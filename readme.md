# Academic Support Online's Collaborate integration.

[![N|Solid](https://cldup.com/dTxpPi9lDf.thumb.png)](https://nodesource.com/products/nsolid)

[![Build Status](https://travis-ci.org/joemccann/dillinger.svg?branch=master)](https://travis-ci.org/joemccann/dillinger)

 ASO is a platform which provides students with academic support via tutorials/workshops, online resources and discussions.  The Collaborate integration applies to the event side of ASO, which allows users to book tutorial/workshop places and manage their attendance.  At the core of the registration system is [Webform](https://www.drupal.org/project/webform).
 
This repository contains the code which powers the Collaborate integration in ASO (Drupal 8).  The integration is spread between 4 modules.

  - collaborate_integration
    - This module is the core provider of the integration by exposing a service in CollaborateService.php.  All functionality in other modules will call this service to perform various actions e.g. enrol user.
  - events_information_management
    - This module handles logic for events by pre-processing node views it will show different information to the user based on registation status / user role.  It also contains a Webform handler to create/delete a Collaborate enrolment on event registration.
  - events_register
    - This module powers the register system for events available to tutors/admins.  This will show the enrolment links for users registered to an event and also allow for status management e.g. tutor cancels student attendance / enrolment.
  - student_event_views
    - This module provides a block and controller to show users all of their registrations.  It also provides them with links to join the session or cancel their attendance. 

# Example workflows:
## Tutor/Admin
Authorised users are allowed to create either tutorial or workshop nodes.  
These contain relevant event information like title, time, description, tutor etc...  Once the information is filled in [Inline Entity Form](https://www.drupal.org/project/inline_entity_form) provides a button to add a Collaborate session.  This will then open a sub form with all the relevant Collaborate options, which the user can then customise.
This is pre-populated by a form alter hook `collaborate_integration_inline_entity_form_entity_form_alter` in `collaborate_integration.module`

Once all the data is filled in the node is saved.  

At this point it will create a Collaborate session entity containing the users selections for the session.  On the entity save/update it will trigger `collaborate_integration_collaborate_session_insert` or `collaborate_integration_collaborate_session_update` in `collaborate_integration.module`.  This will then use the Collaborate service to create the session on the Collaborate server `createSession`.

Once the Collaborate session entity is saved the node save process is then caught by either the `collaborate_integration_node_insert` or `collaborate_integration_node_update` hook in `collaborate_integration.module`.  This will then retrieve the Collaborate session ID from the previously saved entity.  It will then enrol the event admin users e.g. tutor, admins who need access and content creator using the `enrolUser` method from the Collaborate service.

This will then bring the user to the event page which will be pre-processed by the `events_information_management_preprocess_node` hook in `events_information_management`.  This hook will then check the users access.  In this example the user is a staff member who created the event so it will retrieve their link link from the Collaborate service using the `getEnrolment` method.  It will also show the guest link, which is retrieved from the Collaborate session entity.

The user can then go to their event register which will show all users who have registered to attend and their registration links, in case the student/user can't find it.  This is provided by the `EventsRegisterForm` in `events_register` calling the `getEnrolment` method. 

## Student
Student users can browse or search for events relevant to them, when they see an event they want to register for they will use the Webform to register.  During this process the `WebformCollaborateSessionManagement` Webform handler is called.  It checks whether the event has a Collaborate session entity attached to the node.  If it does and their status is 'attending' then it will create an enrolment for them using the Collaborate service `enrolUser` method.

This will then refresh the node view which will be pre-processed by the `events_information_management_preprocess_node` hook in `events_information_management`.  This will then retrieve the users enrolment url via the `getEnrolment` method from the Collaborate service.

Students can also visit their 'registrations and recordings' area which keeps a record of all their events. This is provided by the `RegistrationManagementController` in `student_event_views`.  This will then provide a link to the student via the `getEnrolment` method from the Collaborate service.

This area also provides a link to allow students to cancel their attendance.  This link will trigger a change in the Webform submission, which will also trigger the `WebformCollaborateSessionManagement` Webform handler.  If the status is changed to cancelled it will then delete the users enrolment via the `deleteEnrolment` method in the Collaborate service.

The view will also check if a recording exists and provide a link if it does via the `getRecording` method in from the Collaborate service.
